<?php

namespace App\Service;

use App\Entity\PronosticMetric;
use App\Entity\PronosticSnapshot;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

class PronosticKpiService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{summary: array<string, float|int>, recent: array<int, array<string, mixed>>}
     */
    public function buildDashboard(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        try {
            $snapshots = $this->loadSnapshots($from, $to);
        } catch (\Throwable $exception) {
            if (!self::isMissingTableException($exception)) {
                throw $exception;
            }

            $snapshots = [];
        }

        $totalSnapshots = count($snapshots);
        $fullyComparable = 0;
        $metricSums = [
            PronosticMetric::TYPE_TOP1_ACCURACY => 0.0,
            PronosticMetric::TYPE_TOP3_HIT_RATE => 0.0,
            PronosticMetric::TYPE_MEAN_RANK_ERROR => 0.0,
            PronosticMetric::TYPE_NDCG_AT_5 => 0.0,
        ];
        $metricCounts = [
            PronosticMetric::TYPE_TOP1_ACCURACY => 0,
            PronosticMetric::TYPE_TOP3_HIT_RATE => 0,
            PronosticMetric::TYPE_MEAN_RANK_ERROR => 0,
            PronosticMetric::TYPE_NDCG_AT_5 => 0,
        ];

        $recent = [];

        foreach ($snapshots as $snapshot) {
            if ($snapshot->getComparisonStatus() === PronosticSnapshot::STATUS_COMPARED) {
                ++$fullyComparable;
            }

            $metricMap = [];
            foreach ($snapshot->getMetrics() as $metric) {
                $type = $metric->getMetricType();
                $metricMap[$type] = $metric->getMetricValue();

                if (array_key_exists($type, $metricSums)) {
                    $metricSums[$type] += $metric->getMetricValue();
                    ++$metricCounts[$type];
                }
            }

            $race = $snapshot->getRace();
            $recent[] = [
                'race_id' => $race->getId(),
                'race_date' => $race->getRaceDate(),
                'hippodrome' => $race->getHippodrome()?->getName() ?? $race->getHippodromeName(),
                'meeting_number' => $race->getMeetingNumber(),
                'race_number' => $race->getRaceNumber(),
                'status' => $snapshot->getComparisonStatus(),
                'total_entries' => $snapshot->getTotalEntries(),
                'comparable_entries' => $snapshot->getComparableEntries(),
                'metrics' => $metricMap,
                'compared_at' => $snapshot->getComparedAt(),
                'scoring_mode' => $snapshot->getScoringMode(),
                'scoring_weights' => $snapshot->getScoringWeights(),
            ];
        }

        $coverage = $totalSnapshots > 0 ? ($fullyComparable / $totalSnapshots) : 0.0;

        return [
            'summary' => [
                'total_snapshots' => $totalSnapshots,
                'fully_comparable' => $fullyComparable,
                'coverage' => $coverage,
                'top1_accuracy' => $this->averageMetric($metricSums, $metricCounts, PronosticMetric::TYPE_TOP1_ACCURACY),
                'top3_hit_rate' => $this->averageMetric($metricSums, $metricCounts, PronosticMetric::TYPE_TOP3_HIT_RATE),
                'mean_rank_error' => $this->averageMetric($metricSums, $metricCounts, PronosticMetric::TYPE_MEAN_RANK_ERROR),
                'ndcg_at_5' => $this->averageMetric($metricSums, $metricCounts, PronosticMetric::TYPE_NDCG_AT_5),
            ],
            'recent' => array_slice($recent, 0, 20),
        ];
    }

    /**
     * @return list<PronosticSnapshot>
     */
    private function loadSnapshots(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('s', 'r')
            ->from(PronosticSnapshot::class, 's')
            ->join('s.race', 'r')
            ->where('s.snapshotType = :snapshotType')
            ->setParameter('snapshotType', PronosticSnapshot::TYPE_PRE_RACE)
            ->orderBy('s.updatedAt', 'DESC');

        if ($from instanceof \DateTimeImmutable) {
            $qb->andWhere('r.raceDate >= :fromDate')
                ->setParameter('fromDate', $from->setTime(0, 0));
        }

        if ($to instanceof \DateTimeImmutable) {
            $qb->andWhere('r.raceDate <= :toDate')
                ->setParameter('toDate', $to->setTime(23, 59, 59));
        }

        $result = $qb->getQuery()->getResult();

        return array_values(array_filter($result, static fn ($row): bool => $row instanceof PronosticSnapshot));
    }

    /**
     * @param array<string, float> $metricSums
     * @param array<string, int> $metricCounts
     */
    private function averageMetric(array $metricSums, array $metricCounts, string $metricType): float
    {
        if (($metricCounts[$metricType] ?? 0) <= 0) {
            return 0.0;
        }

        return $metricSums[$metricType] / $metricCounts[$metricType];
    }

    private static function isMissingTableException(\Throwable $exception): bool
    {
        if ($exception instanceof TableNotFoundException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'relation') && str_contains($message, 'does not exist');
    }
}
