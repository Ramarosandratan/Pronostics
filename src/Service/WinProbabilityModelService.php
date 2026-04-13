<?php

namespace App\Service;

use App\Entity\Participation;
use Doctrine\ORM\EntityManagerInterface;

class WinProbabilityModelService
{
    public const FEATURE_NAMES = [
        'bias',
        'saddle',
        'odds',
        'performance',
        'age',
        'earnings',
        'distance',
        'field_size',
        'autostart',
    ];

    private const VERSION = 1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WinProbabilityFeatureExtractor $featureExtractor,
        private readonly WinProbabilityModelStore $modelStore,
    ) {
    }

    public function hasModel(): bool
    {
        return $this->modelStore->hasModel();
    }

    public function predictParticipationProbability(Participation $participation, int $fieldSize): ?float
    {
        $model = $this->modelStore->loadModel();
        if ($model === null) {
            return null;
        }

        $features = $this->featureExtractor->extractFeatures($participation, $fieldSize);

        return $this->predictWithModel($features, $model);
    }

    /**
     * @return array{trained: bool, samples: int, positive_samples: int, loss: float, accuracy: float, model_path: string, reason?: string}
     */
    public function trainModel(): array
    {
        return $this->trainFromSamples($this->buildTrainingSamples());
    }

    /**
     * @param array<int, array{features: array<string, float>, label: int|float}> $samples
     * @return array{trained: bool, samples: int, positive_samples: int, loss: float, accuracy: float, model_path: string, reason?: string}
     */
    public function trainFromSamples(array $samples): array
    {
        $preparedSamples = $this->normalizeTrainingSamples($samples);
        $sampleCount = count($preparedSamples);
        $positiveCount = count(array_filter($preparedSamples, static fn (array $sample): bool => (float) $sample['label'] >= 0.5));

        if ($sampleCount === 0) {
            return [
                'trained' => false,
                'samples' => 0,
                'positive_samples' => 0,
                'loss' => 0.0,
                'accuracy' => 0.0,
                'model_path' => $this->modelStore->getModelPath(),
                'reason' => 'Aucune donnee exploitable pour l entrainement.',
            ];
        }

        $negativeCount = $sampleCount - $positiveCount;
        if ($positiveCount === 0 || $negativeCount === 0) {
            return [
                'trained' => false,
                'samples' => $sampleCount,
                'positive_samples' => $positiveCount,
                'loss' => 0.0,
                'accuracy' => 0.0,
                'model_path' => $this->modelStore->getModelPath(),
                'reason' => 'Le jeu de donnees doit contenir des gagnants et des perdants.',
            ];
        }

        $model = $this->fitModel($preparedSamples, $positiveCount, $negativeCount);
        $metrics = $this->evaluateModel($preparedSamples, $model);

        $payload = [
            'version' => self::VERSION,
            'trained_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'samples' => $sampleCount,
            'positive_samples' => $positiveCount,
            'feature_names' => self::FEATURE_NAMES,
            'weights' => $model['weights'],
            'learning_rate' => $model['learning_rate'],
            'epochs' => $model['epochs'],
            'regularization' => $model['regularization'],
            'loss' => $metrics['loss'],
            'accuracy' => $metrics['accuracy'],
        ];

        $this->modelStore->saveModel($payload);

        return [
            'trained' => true,
            'samples' => $sampleCount,
            'positive_samples' => $positiveCount,
            'loss' => $metrics['loss'],
            'accuracy' => $metrics['accuracy'],
                'model_path' => $this->modelStore->getModelPath(),
        ];
    }

    /**
     * @return array<int, array{features: array<string, float>, label: int}>
     */
    private function buildTrainingSamples(): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT p
            FROM App\\Entity\\Participation p
            JOIN p.race r
            WHERE p.finishingPosition IS NOT NULL AND p.finishingPosition > 0
            ORDER BY r.raceDate ASC, r.id ASC, p.id ASC'
        )->getResult();

        $fieldSizes = [];
        foreach ($rows as $row) {
            if (!$row instanceof Participation) {
                continue;
            }

            $raceId = $row->getRace()->getId();
            if ($raceId === null) {
                continue;
            }

            $fieldSizes[$raceId] = ($fieldSizes[$raceId] ?? 0) + 1;
        }

        $samples = [];
        foreach ($rows as $row) {
            if (!$row instanceof Participation) {
                continue;
            }

            $raceId = $row->getRace()->getId();
            $fieldSize = $raceId !== null && isset($fieldSizes[$raceId]) ? $fieldSizes[$raceId] : count($rows);

            $samples[] = [
                'features' => $this->featureExtractor->extractFeatures($row, max(1, $fieldSize)),
                'label' => (int) ($row->getFinishingPosition() === 1 ? 1 : 0),
            ];
        }

        return $samples;
    }

    /**
     * @param array<int, array{features: array<string, float>, label: int|float}> $samples
     * @return array<int, array{features: array<string, float>, label: float}>
     */
    private function normalizeTrainingSamples(array $samples): array
    {
        $normalized = [];

        foreach ($samples as $sample) {
            if (!isset($sample['features']) || !is_array($sample['features'])) {
                continue;
            }

            $features = [];
            foreach (self::FEATURE_NAMES as $featureName) {
                $features[$featureName] = $this->clamp((float) ($sample['features'][$featureName] ?? 0.0));
            }

            $normalized[] = [
                'features' => $features,
                'label' => (float) ($sample['label'] ?? 0.0),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{features: array<string, float>, label: float}> $samples
     * @return array{weights: array<string, float>, learning_rate: float, epochs: int, regularization: float}
     */
    private function fitModel(array $samples, int $positiveCount, int $negativeCount): array
    {
        $weights = array_fill_keys(self::FEATURE_NAMES, 0.0);
        $learningRate = 0.45;
        $regularization = 0.0015;
        $epochs = 220;
        $positiveWeight = 0.5 / $positiveCount;
        $negativeWeight = 0.5 / $negativeCount;
        $sampleCount = count($samples);

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            $gradients = array_fill_keys(self::FEATURE_NAMES, 0.0);

            foreach ($samples as $sample) {
                $label = (float) $sample['label'];
                $features = $sample['features'];
                $prediction = $this->predictWithWeights($features, $weights);
                $sampleWeight = $label >= 0.5 ? $positiveWeight : $negativeWeight;
                $error = ($prediction - $label) * $sampleWeight;

                foreach (self::FEATURE_NAMES as $featureName) {
                    $gradients[$featureName] += $error * $features[$featureName];
                }
            }

            foreach (self::FEATURE_NAMES as $featureName) {
                $gradient = $gradients[$featureName] / max(1, $sampleCount);
                if ($featureName !== 'bias') {
                    $gradient += $regularization * $weights[$featureName];
                }

                $weights[$featureName] -= $learningRate * $gradient;
            }
        }

        return [
            'weights' => $weights,
            'learning_rate' => $learningRate,
            'epochs' => $epochs,
            'regularization' => $regularization,
        ];
    }

    /**
     * @param array<int, array{features: array<string, float>, label: float}> $samples
     * @param array{weights: array<string, float>, learning_rate: float, epochs: int, regularization: float} $model
     * @return array{loss: float, accuracy: float}
     */
    private function evaluateModel(array $samples, array $model): array
    {
        $loss = 0.0;
        $correct = 0;
        $sampleCount = count($samples);

        foreach ($samples as $sample) {
            $label = (float) $sample['label'];
            $probability = $this->predictWithModel($sample['features'], $model);
            $probability = max(1e-9, min(1.0 - 1e-9, $probability));

            $loss += -($label * log($probability) + (1.0 - $label) * log(1.0 - $probability));
            if (($probability >= 0.5 && $label >= 0.5) || ($probability < 0.5 && $label < 0.5)) {
                $correct++;
            }
        }

        return [
            'loss' => round($loss / max(1, $sampleCount), 6),
            'accuracy' => round($correct / max(1, $sampleCount), 6),
        ];
    }

    /**
     * @param array<string, float> $features
     * @param array{weights: array<string, float>} $model
     */
    private function predictWithModel(array $features, array $model): float
    {
        return $this->predictWithWeights($features, $model['weights']);
    }

    /**
     * @param array<string, float> $features
     * @param array<string, float> $weights
     */
    private function predictWithWeights(array $features, array $weights): float
    {
        $sum = 0.0;
        foreach (self::FEATURE_NAMES as $featureName) {
            $sum += ($weights[$featureName] ?? 0.0) * ($features[$featureName] ?? 0.0);
        }

        return $this->sigmoid($sum);
    }

    private function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

}
