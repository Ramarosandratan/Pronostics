<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\StreamedResponse;

class PronosticCsvExportService
{
    /**
     * @param array<string> $headers
     * @param iterable<array<int, string|int|float|null>> $rows
     */
    public function createCsvResponse(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    /**
     * @return array<string>
     */
    public function raceHeaders(): array
    {
        return [
            'rank',
            'horse_name',
            'saddle_number',
            'score',
            'sub_score_position',
            'sub_score_odds',
            'sub_score_performance',
            'sub_score_earnings',
            'sub_score_age',
            'scoring_mode',
            'weight_position',
            'weight_odds',
            'weight_performance',
            'weight_earnings',
            'weight_age',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rankings
     * @param array<string, float> $weights
     * @return iterable<array<int, string|int|float|null>>
     */
    public function raceRows(array $rankings, string $mode, array $weights): iterable
    {
        foreach ($rankings as $row) {
            $subScores = isset($row['sub_scores']) && is_array($row['sub_scores']) ? $row['sub_scores'] : [];

            yield [
                isset($row['rank']) ? (int) $row['rank'] : 0,
                (string) ($row['horse_name'] ?? ''),
                isset($row['saddle_number']) ? (int) $row['saddle_number'] : null,
                $this->formatFloat($row['score'] ?? null),
                $this->formatFloat($subScores['position'] ?? null),
                $this->formatFloat($subScores['odds'] ?? null),
                $this->formatFloat($subScores['performance'] ?? null),
                $this->formatFloat($subScores['earnings'] ?? null),
                $this->formatFloat($subScores['age'] ?? null),
                $mode,
                $this->formatFloat($weights['position'] ?? null),
                $this->formatFloat($weights['odds'] ?? null),
                $this->formatFloat($weights['performance'] ?? null),
                $this->formatFloat($weights['earnings'] ?? null),
                $this->formatFloat($weights['age'] ?? null),
            ];
        }
    }

    /**
     * @return array<string>
     */
    public function dashboardHeaders(): array
    {
        return [
            'race_id',
            'race_date',
            'hippodrome',
            'meeting_number',
            'race_number',
            'status',
            'comparable_entries',
            'total_entries',
            'top1_accuracy',
            'top3_hit_rate',
            'mean_rank_error',
            'ndcg_at_5',
            'scoring_mode',
            'weight_position',
            'weight_odds',
            'weight_performance',
            'weight_earnings',
            'weight_age',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $recentRows
     * @return iterable<array<int, string|int|float|null>>
     */
    public function dashboardRows(array $recentRows): iterable
    {
        foreach ($recentRows as $row) {
            yield $this->dashboardRow($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string|int|float|null>
     */
    private function dashboardRow(array $row): array
    {
        $metrics = $this->extractArray($row, 'metrics');
        $weights = $this->extractArray($row, 'scoring_weights');

        return [
            isset($row['race_id']) ? (int) $row['race_id'] : 0,
            $row['race_date'] instanceof \DateTimeInterface ? $row['race_date']->format('Y-m-d') : null,
            (string) ($row['hippodrome'] ?? ''),
            isset($row['meeting_number']) ? (int) $row['meeting_number'] : null,
            isset($row['race_number']) ? (int) $row['race_number'] : null,
            (string) ($row['status'] ?? ''),
            isset($row['comparable_entries']) ? (int) $row['comparable_entries'] : 0,
            isset($row['total_entries']) ? (int) $row['total_entries'] : 0,
            $this->formatFloat($metrics['top1_accuracy'] ?? null),
            $this->formatFloat($metrics['top3_hit_rate'] ?? null),
            $this->formatFloat($metrics['mean_rank_error'] ?? null),
            $this->formatFloat($metrics['ndcg_at_5'] ?? null),
            (string) ($row['scoring_mode'] ?? PronosticScoringService::MODE_CONSERVATIVE),
            $this->formatFloat($weights['position'] ?? null),
            $this->formatFloat($weights['odds'] ?? null),
            $this->formatFloat($weights['performance'] ?? null),
            $this->formatFloat($weights['earnings'] ?? null),
            $this->formatFloat($weights['age'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function extractArray(array $row, string $key): array
    {
        $value = $row[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private function formatFloat(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }
}
