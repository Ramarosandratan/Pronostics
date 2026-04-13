<?php

namespace App\Service;

use App\Entity\Participation;

class WinProbabilityFeatureExtractor
{
    /**
     * @return array<string, float>
     */
    public function extractFeatures(Participation $participation, int $fieldSize): array
    {
        $race = $participation->getRace();

        return [
            'bias' => 1.0,
            'saddle' => $this->normalizeSaddleNumber($participation->getSaddleNumber(), $fieldSize),
            'odds' => $this->normalizeOdds($participation->getOdds()),
            'performance' => $this->normalizePerformance($participation->getPerformanceIndicator()),
            'age' => $this->normalizeAge($participation->getAgeAtRace()),
            'earnings' => $this->normalizeEarnings($this->toFloat($participation->getCareerEarnings())),
            'distance' => $this->normalizeDistance($race->getDistanceMeters()),
            'field_size' => $this->normalizeFieldSize($fieldSize),
            'autostart' => $this->normalizeAutostart($race->isAutostart()),
        ];
    }

    private function normalizeSaddleNumber(?int $saddleNumber, int $fieldSize): float
    {
        if ($saddleNumber === null || $saddleNumber <= 0) {
            return 0.5;
        }

        if ($fieldSize <= 1) {
            return 1.0;
        }

        return $this->clamp(1.0 - (($saddleNumber - 1) / max(1, $fieldSize - 1)));
    }

    private function normalizeOdds(?float $odds): float
    {
        if ($odds === null || $odds <= 0.0) {
            return 0.5;
        }

        return $this->clamp(1.0 / (1.0 + $odds));
    }

    private function normalizePerformance(?string $indicator): float
    {
        if ($indicator === null || trim($indicator) === '') {
            return 0.5;
        }

        preg_match_all('/\d+/', $indicator, $matches);
        if ($matches[0] === []) {
            return 0.5;
        }

        $values = array_map('intval', $matches[0]);
        $average = array_sum($values) / count($values);
        $boundedAverage = max(1.0, min(20.0, $average));

        return $this->clamp((21.0 - $boundedAverage) / 20.0);
    }

    private function normalizeAge(?int $age): float
    {
        if ($age === null || $age <= 0) {
            return 0.5;
        }

        return $this->clamp(1.0 - (abs($age - 5) / 10.0));
    }

    private function normalizeEarnings(float $earnings): float
    {
        if ($earnings <= 0.0) {
            return 0.5;
        }

        return $this->clamp(min(1.0, log10($earnings + 1.0) / 8.0));
    }

    private function normalizeDistance(?int $distanceMeters): float
    {
        if ($distanceMeters === null || $distanceMeters <= 0) {
            return 0.5;
        }

        return $this->clamp(min(1.0, $distanceMeters / 4000.0));
    }

    private function normalizeFieldSize(int $fieldSize): float
    {
        return $this->clamp(min(1.0, max(1, $fieldSize) / 20.0));
    }

    private function normalizeAutostart(?bool $autostart): float
    {
        if ($autostart === null) {
            return 0.5;
        }

        return $autostart ? 1.0 : 0.0;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function toFloat(?string $value): float
    {
        if ($value === null || trim($value) === '') {
            return 0.0;
        }

        return (float) $value;
    }
}
