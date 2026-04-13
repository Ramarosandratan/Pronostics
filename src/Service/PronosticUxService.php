<?php

namespace App\Service;

class PronosticUxService
{
    /**
     * @param array<int, array<string, mixed>> $rankings
     * @return array<int, array<string, mixed>>
     */
    public function enrichRankingsForUx(array $rankings): array
    {
        if ($rankings === []) {
            return [];
        }

        $mlValues = [];
        foreach ($rankings as $row) {
            if (!isset($row['ml_probability']) || $row['ml_probability'] === null) {
                continue;
            }

            $mlValues[] = (float) $row['ml_probability'];
        }

        $hasMlProbabilities = $mlValues !== [];
        $mlAreDiscriminative = $hasMlProbabilities && ((max($mlValues) - min($mlValues)) >= 0.1);

        if ($hasMlProbabilities && $mlAreDiscriminative) {
            foreach ($rankings as &$row) {
                $row['win_probability'] = round((float) ($row['ml_probability'] ?? 0.0), 1);
            }
            unset($row);

            return $rankings;
        }

        $temperature = 12.0;
        $maxScore = max(array_map(static fn (array $row): float => (float) ($row['score'] ?? 0.0), $rankings));
        $sum = 0.0;
        $expByIndex = [];

        foreach ($rankings as $index => $row) {
            $score = (float) ($row['score'] ?? 0.0);
            $exp = exp(($score - $maxScore) / $temperature);
            $expByIndex[$index] = $exp;
            $sum += $exp;
        }

        foreach ($rankings as $index => &$row) {
            $probability = $sum > 0.0 ? ($expByIndex[$index] / $sum) * 100.0 : 0.0;
            $row['win_probability'] = round($probability, 1);
        }
        unset($row);

        return $rankings;
    }

    /**
     * @param array<int, array<string, mixed>> $rankings
     * @return array{percent: float, stars: int, label: string, reason: string}
     */
    public function buildRaceConfidence(array $rankings): array
    {
        if (count($rankings) < 2) {
            return [
                'percent' => 52.0,
                'stars' => 3,
                'label' => 'Moyenne',
                'reason' => 'Donnees insuffisantes pour estimer une confiance fiable.',
            ];
        }

        $top1Probability = max(0.0, (float) ($rankings[0]['win_probability'] ?? 0.0));
        $top2Probability = max(0.0, (float) ($rankings[1]['win_probability'] ?? 0.0));
        $gapSignal = max(0.0, min(1.0, ($top1Probability - $top2Probability) / 8.0));

        $dataCoverage = $this->computeSubScoreCoverage($rankings);
        $concentration = $this->computeProbabilityConcentration($rankings);

        // Keep confidence in a practical range: avoids perpetually low values on large fields.
        $confidencePercent = round(
            max(
                40.0,
                min(
                    95.0,
                    40.0
                    + ($concentration * 35.0)
                    + ($gapSignal * 15.0)
                    + (min(1.0, $top1Probability / 35.0) * 5.0)
                    + ($dataCoverage * 5.0)
                )
            ),
            1
        );

        if ($confidencePercent < 52.0) {
            $confidencePercent = 52.0;
        }

        $stars = max(3, min(5, (int) round($confidencePercent / 20.0)));

        return [
            'percent' => $confidencePercent,
            'stars' => $stars,
            'label' => $this->resolveConfidenceLabel($confidencePercent),
            'reason' => $this->resolveConfidenceReason($confidencePercent),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function buildExplanationText(array $row): string
    {
        $horseName = (string) ($row['horse_name'] ?? 'Ce cheval');
        $rank = (int) ($row['rank'] ?? 0);
        $subScores = isset($row['sub_scores']) && is_array($row['sub_scores']) ? $row['sub_scores'] : [];

        $labels = [
            'position' => 'sa regularite recente',
            'odds' => 'une cote favorable',
            'performance' => 'ses performances recentes',
            'earnings' => 'son niveau de gains',
            'age' => 'son profil d age adapte',
        ];

        $sortable = [];
        foreach ($subScores as $key => $value) {
            if (!array_key_exists((string) $key, $labels)) {
                continue;
            }

            $sortable[] = [
                'key' => (string) $key,
                'value' => (float) $value,
            ];
        }

        usort($sortable, static fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        $driverA = isset($sortable[0]) ? $labels[$sortable[0]['key']] : 'un ensemble de signaux favorables';
        $driverB = isset($sortable[1]) ? $labels[$sortable[1]['key']] : 'la coherence de ses indicateurs';
        $probability = (float) ($row['win_probability'] ?? 0.0);

        return sprintf(
            'Notre algorithme place %s en position %d avec %.1f%% de probabilite de victoire, principalement grace a %s et %s.',
            $horseName,
            max(1, $rank),
            $probability,
            $driverA,
            $driverB
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rankings
     */
    private function computeSubScoreCoverage(array $rankings): float
    {
        $nonZeroSubScores = 0;
        $subScoreCount = 0;

        foreach ($rankings as $row) {
            $subScores = isset($row['sub_scores']) && is_array($row['sub_scores']) ? $row['sub_scores'] : [];
            foreach ($subScores as $value) {
                $subScoreCount++;
                if ((float) $value > 0.0) {
                    $nonZeroSubScores++;
                }
            }
        }

        return $subScoreCount > 0 ? ($nonZeroSubScores / $subScoreCount) : 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $rankings
     */
    private function computeProbabilityConcentration(array $rankings): float
    {
        $probabilities = [];
        foreach ($rankings as $row) {
            $p = max(0.0, (float) ($row['win_probability'] ?? 0.0)) / 100.0;
            if ($p > 0.0) {
                $probabilities[] = $p;
            }
        }

        $n = count($probabilities);
        if ($n < 2) {
            return 0.0;
        }

        $entropy = 0.0;
        foreach ($probabilities as $p) {
            $entropy -= $p * log($p);
        }

        $maxEntropy = log((float) $n);
        if ($maxEntropy <= 0.0) {
            return 0.0;
        }

        $normalizedEntropy = $entropy / $maxEntropy;

        return max(0.0, min(1.0, 1.0 - $normalizedEntropy));
    }

    private function resolveConfidenceLabel(float $confidencePercent): string
    {
        if ($confidencePercent >= 70.0) {
            return 'Elevee';
        }

        if ($confidencePercent >= 52.0) {
            return 'Moyenne';
        }

        return 'Faible';
    }

    private function resolveConfidenceReason(float $confidencePercent): string
    {
        if ($confidencePercent >= 70.0) {
            return 'Confiance forte: leader detache et signaux historiques coherents.';
        }

        if ($confidencePercent >= 45.0) {
            return 'Confiance intermediaire: avantage du leader present mais concurrence proche.';
        }

        return 'Confiance reduite: donnees limitees ou ecarts faibles entre favoris.';
    }
}
