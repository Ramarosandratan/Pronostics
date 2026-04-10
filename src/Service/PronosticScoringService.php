<?php

namespace App\Service;

use App\Entity\Participation;
use App\Entity\Race;
use Doctrine\ORM\EntityManagerInterface;

class PronosticScoringService
{
    public const MODE_CONSERVATIVE = 'conservative';
    public const MODE_AGGRESSIVE = 'aggressive';

    private const WEIGHT_KEYS = ['position', 'odds', 'performance', 'earnings', 'age'];
    private const DEFAULT_WEIGHTS = [
        'position' => 45.0,
        'odds' => 25.0,
        'performance' => 15.0,
        'earnings' => 10.0,
        'age' => 5.0,
    ];

    /**
     * @param array<string, array<string, int|float|string>> $scoringProfiles
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $scoringProfiles = [],
        private readonly string $defaultMode = self::MODE_CONSERVATIVE,
    )
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scoreRace(Race $race, ?string $mode = null): array
    {
        $configuration = $this->resolveScoringConfiguration($mode);
        $weights = $configuration['weights'];

        $participations = $this->entityManager->createQuery(
            'SELECT p, h
            FROM App\\Entity\\Participation p
            JOIN p.horse h
            WHERE p.race = :race
            ORDER BY p.saddleNumber ASC, p.id ASC'
        )->setParameter('race', $race)->getResult();

        if ($participations === []) {
            return [];
        }

        $oddsRange = $this->buildRange($participations, static fn (Participation $p): ?float => $p->getOdds());
        $earningsRange = $this->buildRange($participations, static fn (Participation $p): ?float => self::toFloat($p->getCareerEarnings()));

        $scored = [];
        $fieldSize = count($participations);

        foreach ($participations as $participation) {
            $positionScore = $this->positionScore($participation->getFinishingPosition(), $fieldSize);
            $oddsScore = $this->inverseRangeScore($participation->getOdds(), $oddsRange);
            $performanceScore = $this->performanceScore($participation->getPerformanceIndicator());
            $earningsScore = $this->directRangeScore(self::toFloat($participation->getCareerEarnings()), $earningsRange);
            $ageScore = $this->ageScore($participation->getAgeAtRace());

            $globalScore = (
                ($positionScore * $weights['position'])
                + ($oddsScore * $weights['odds'])
                + ($performanceScore * $weights['performance'])
                + ($earningsScore * $weights['earnings'])
                + ($ageScore * $weights['age'])
            ) / 100.0;

            $scored[] = [
                'participation_id' => $participation->getId(),
                'horse_id' => $participation->getHorse()->getId(),
                'horse_name' => $participation->getHorse()->getName(),
                'saddle_number' => $participation->getSaddleNumber(),
                'score' => round($globalScore, 2),
                'sub_scores' => [
                    'position' => round($positionScore, 2),
                    'odds' => round($oddsScore, 2),
                    'performance' => round($performanceScore, 2),
                    'earnings' => round($earningsScore, 2),
                    'age' => round($ageScore, 2),
                ],
            ];
        }

        usort($scored, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $left['score'] < $right['score'] ? 1 : -1;
            }

            $leftSaddle = $left['saddle_number'] ?? PHP_INT_MAX;
            $rightSaddle = $right['saddle_number'] ?? PHP_INT_MAX;
            if ($leftSaddle !== $rightSaddle) {
                return $leftSaddle <=> $rightSaddle;
            }

            return ($left['participation_id'] ?? PHP_INT_MAX) <=> ($right['participation_id'] ?? PHP_INT_MAX);
        });

        foreach ($scored as $index => &$item) {
            $item['rank'] = $index + 1;
        }
        unset($item);

        return $scored;
    }

    /**
     * @return array{mode: string, weights: array{position: float, odds: float, performance: float, earnings: float, age: float}}
     */
    public function resolveScoringConfiguration(?string $mode = null): array
    {
        $effectiveMode = $this->normalizeMode($mode);
        $profiles = $this->scoringProfiles;
        $profile = $profiles[$effectiveMode] ?? self::DEFAULT_WEIGHTS;

        $weights = [];
        foreach (self::WEIGHT_KEYS as $key) {
            $raw = $profile[$key] ?? self::DEFAULT_WEIGHTS[$key];
            $weights[$key] = max(0.0, (float) $raw);
        }

        $total = array_sum($weights);
        if ($total <= 0.0) {
            $weights = self::DEFAULT_WEIGHTS;
            $total = 100.0;
        }

        if (abs($total - 100.0) > 0.00001) {
            foreach (self::WEIGHT_KEYS as $key) {
                $weights[$key] = ($weights[$key] / $total) * 100.0;
            }
        }

        return [
            'mode' => $effectiveMode,
            'weights' => $weights,
        ];
    }

    private function normalizeMode(?string $mode): string
    {
        $normalizedMode = strtolower(trim((string) $mode));
        if (!in_array($normalizedMode, [self::MODE_CONSERVATIVE, self::MODE_AGGRESSIVE], true)) {
            $normalizedMode = strtolower(trim($this->defaultMode));
        }

        if (!in_array($normalizedMode, [self::MODE_CONSERVATIVE, self::MODE_AGGRESSIVE], true)) {
            return self::MODE_CONSERVATIVE;
        }

        return $normalizedMode;
    }

    private function positionScore(?int $position, int $fieldSize): float
    {
        if ($position === null || $position <= 0 || $fieldSize <= 0) {
            return 0.0;
        }

        $normalizedPosition = min($position, $fieldSize);

        return (($fieldSize - $normalizedPosition + 1) / $fieldSize) * 100.0;
    }

    /**
     * @param array{min: float, max: float}|null $range
     */
    private function inverseRangeScore(?float $value, ?array $range): float
    {
        if ($value === null || $value <= 0 || $range === null) {
            return 0.0;
        }

        if ($range['max'] === $range['min']) {
            return 100.0;
        }

        $clamped = max($range['min'], min($value, $range['max']));

        return (($range['max'] - $clamped) / ($range['max'] - $range['min'])) * 100.0;
    }

    /**
     * @param array{min: float, max: float}|null $range
     */
    private function directRangeScore(?float $value, ?array $range): float
    {
        if ($value === null || $range === null) {
            return 0.0;
        }

        if ($range['max'] === $range['min']) {
            return 100.0;
        }

        $clamped = max($range['min'], min($value, $range['max']));

        return (($clamped - $range['min']) / ($range['max'] - $range['min'])) * 100.0;
    }

    private function performanceScore(?string $indicator): float
    {
        if ($indicator === null || trim($indicator) === '') {
            return 0.0;
        }

        preg_match_all('/\d+/', $indicator, $matches);
        if ($matches[0] === []) {
            return 0.0;
        }

        $values = array_map('intval', $matches[0]);
        $average = array_sum($values) / count($values);
        $boundedAverage = max(1.0, min(20.0, $average));

        return ((21.0 - $boundedAverage) / 20.0) * 100.0;
    }

    private function ageScore(?int $age): float
    {
        if ($age === null || $age <= 0) {
            return 0.0;
        }

        $score = 100.0 - (abs($age - 5) * 20.0);

        return max(0.0, min(100.0, $score));
    }

    /**
     * @param Participation[] $participations
     * @param callable(Participation): ?float $extractor
     * @return array{min: float, max: float}|null
     */
    private function buildRange(array $participations, callable $extractor): ?array
    {
        $values = [];

        foreach ($participations as $participation) {
            $value = $extractor($participation);
            if ($value === null) {
                continue;
            }

            $values[] = $value;
        }

        if ($values === []) {
            return null;
        }

        return [
            'min' => min($values),
            'max' => max($values),
        ];
    }

    private static function toFloat(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return (float) $value;
    }
}
