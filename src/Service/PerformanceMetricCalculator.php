<?php

namespace App\Service;

class PerformanceMetricCalculator
{
    /**
     * @param array<int, array{participation_id: int, rank: int}> $predictions
     * @param array<int, int> $actualPositionsByParticipation
     *
     * @return array{top1_accuracy: float, top3_hit_rate: float, mean_rank_error: float, ndcg_at_5: float, comparable_entries: int}
     */
    public function calculate(array $predictions, array $actualPositionsByParticipation): array
    {
        $predictedTop1 = $predictions[0]['participation_id'] ?? null;
        $actualWinnerParticipationId = $this->findParticipationByActualRank($actualPositionsByParticipation, 1);

        $top1Accuracy = ($predictedTop1 !== null && isset($actualPositionsByParticipation[$predictedTop1]) && $actualPositionsByParticipation[$predictedTop1] === 1)
            ? 1.0
            : 0.0;

        $top3Ids = array_column(array_slice($predictions, 0, 3), 'participation_id');
        $top3HitRate = ($actualWinnerParticipationId !== null && in_array($actualWinnerParticipationId, $top3Ids, true))
            ? 1.0
            : 0.0;

        $rankErrorSum = 0.0;
        $comparableEntries = 0;

        foreach ($predictions as $prediction) {
            $participationId = $prediction['participation_id'];
            if (!isset($actualPositionsByParticipation[$participationId])) {
                continue;
            }

            $rankErrorSum += abs($prediction['rank'] - $actualPositionsByParticipation[$participationId]);
            ++$comparableEntries;
        }

        $meanRankError = $comparableEntries > 0 ? $rankErrorSum / $comparableEntries : 0.0;

        $ndcgAt5 = $this->computeNdcgAtFive($predictions, $actualPositionsByParticipation);

        return [
            'top1_accuracy' => $top1Accuracy,
            'top3_hit_rate' => $top3HitRate,
            'mean_rank_error' => $meanRankError,
            'ndcg_at_5' => $ndcgAt5,
            'comparable_entries' => $comparableEntries,
        ];
    }

    /**
     * @param array<int, int> $actualPositionsByParticipation
     */
    private function findParticipationByActualRank(array $actualPositionsByParticipation, int $rank): ?int
    {
        foreach ($actualPositionsByParticipation as $participationId => $actualRank) {
            if ($actualRank === $rank) {
                return $participationId;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{participation_id: int, rank: int}> $predictions
     * @param array<int, int> $actualPositionsByParticipation
     */
    private function computeNdcgAtFive(array $predictions, array $actualPositionsByParticipation): float
    {
        $predictedTopFive = array_slice($predictions, 0, 5);
        if ($predictedTopFive === []) {
            return 0.0;
        }

        $dcg = 0.0;
        foreach ($predictedTopFive as $index => $prediction) {
            $actualPosition = $actualPositionsByParticipation[$prediction['participation_id']] ?? null;
            $relevance = $this->positionToRelevance($actualPosition);
            $dcg += (2 ** $relevance - 1) / log($index + 2, 2);
        }

        $allRelevances = [];
        foreach ($actualPositionsByParticipation as $actualPosition) {
            $allRelevances[] = $this->positionToRelevance($actualPosition);
        }

        rsort($allRelevances);
        $idealRelevances = array_slice($allRelevances, 0, 5);
        $idcg = 0.0;
        foreach ($idealRelevances as $index => $relevance) {
            $idcg += (2 ** $relevance - 1) / log($index + 2, 2);
        }

        if ($idcg <= 0.0) {
            return 0.0;
        }

        return $dcg / $idcg;
    }

    private function positionToRelevance(?int $actualPosition): int
    {
        if ($actualPosition === null || $actualPosition <= 0 || $actualPosition > 5) {
            return 0;
        }

        return 6 - $actualPosition;
    }
}
