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

        $hasMlProbabilities = isset($rankings[0]['ml_probability']) && $rankings[0]['ml_probability'] !== null;
        if ($hasMlProbabilities) {
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
                'percent' => 0.0,
                'stars' => 0,
                'label' => 'Faible',
                'reason' => 'Donnees insuffisantes pour estimer une confiance fiable.',
            ];
        }

        $top1 = (float) ($rankings[0]['score'] ?? 0.0);
        $top2 = (float) ($rankings[1]['score'] ?? 0.0);
        $top1Probability = ((float) ($rankings[0]['win_probability'] ?? 0.0)) / 100.0;
        $gapSignal = max(0.0, min(1.0, ($top1 - $top2) / 15.0));

        $dataCoverage = $this->computeSubScoreCoverage($rankings);

        $raw = (0.45 * $top1Probability) + (0.35 * $gapSignal) + (0.20 * $dataCoverage);
        $fieldSize = count($rankings);
        $fieldPenalty = $fieldSize <= 8 ? 1.0 : max(0.65, 8.0 / $fieldSize);
        $confidencePercent = round(max(0.0, min(100.0, $raw * $fieldPenalty * 100.0)), 1);

        return [
            'percent' => $confidencePercent,
            'stars' => (int) round($confidencePercent / 20.0),
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

    private function resolveConfidenceLabel(float $confidencePercent): string
    {
        if ($confidencePercent >= 70.0) {
            return 'Elevee';
        }

        if ($confidencePercent >= 45.0) {
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
