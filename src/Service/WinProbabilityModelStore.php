<?php

namespace App\Service;

class WinProbabilityModelStore
{
    private ?array $cachedModel = null;
    private bool $modelLoaded = false;

    public function __construct(private readonly string $modelPath)
    {
    }

    public function getModelPath(): string
    {
        return $this->modelPath;
    }

    public function hasModel(): bool
    {
        return $this->loadModel() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadModel(): ?array
    {
        if ($this->modelLoaded) {
            return $this->cachedModel;
        }

        $this->modelLoaded = true;
        $model = null;

        if (is_file($this->modelPath)) {
            $contents = @file_get_contents($this->modelPath);
            if ($contents !== false && trim($contents) !== '') {
                $decoded = json_decode($contents, true);
                if (is_array($decoded) && isset($decoded['weights']) && is_array($decoded['weights'])) {
                    $featureNames = $decoded['feature_names'] ?? WinProbabilityModelService::FEATURE_NAMES;
                    $weights = [];
                    foreach ($featureNames as $featureName) {
                        $weights[(string) $featureName] = (float) ($decoded['weights'][$featureName] ?? 0.0);
                    }

                    $model = [
                        'version' => (int) ($decoded['version'] ?? 1),
                        'trained_at' => (string) ($decoded['trained_at'] ?? ''),
                        'samples' => (int) ($decoded['samples'] ?? 0),
                        'positive_samples' => (int) ($decoded['positive_samples'] ?? 0),
                        'feature_names' => array_values(array_map('strval', $featureNames)),
                        'weights' => $weights,
                        'learning_rate' => (float) ($decoded['learning_rate'] ?? 0.0),
                        'epochs' => (int) ($decoded['epochs'] ?? 0),
                        'regularization' => (float) ($decoded['regularization'] ?? 0.0),
                        'loss' => (float) ($decoded['loss'] ?? 0.0),
                        'accuracy' => (float) ($decoded['accuracy'] ?? 0.0),
                    ];
                }
            }
        }

        $this->cachedModel = $model;

        return $this->cachedModel;
    }

    public function saveModel(array $model): void
    {
        $directory = dirname($this->modelPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $this->modelPath,
            json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: ''
        );

        $this->cachedModel = $model;
        $this->modelLoaded = true;
    }
}
