<?php

namespace App\Service;

use App\Entity\Participation;
use App\Entity\PronosticMetric;
use App\Entity\PronosticSnapshot;
use App\Entity\Race;
use Doctrine\ORM\EntityManagerInterface;

class PronosticComparisonService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerformanceMetricCalculator $metricCalculator,
    ) {
    }

    public function compareRace(Race $race): void
    {
        $snapshot = $this->entityManager->getRepository(PronosticSnapshot::class)->findOneBy([
            'race' => $race,
            'snapshotType' => PronosticSnapshot::TYPE_PRE_RACE,
        ]);

        if (!$snapshot instanceof PronosticSnapshot) {
            return;
        }

        $predictions = $snapshot->getPredictions()->toArray();
        usort($predictions, static fn ($left, $right): int => $left->getPredictedRank() <=> $right->getPredictedRank());

        $actualRows = $this->entityManager->createQuery(
            'SELECT p FROM App\\Entity\\Participation p
            WHERE p.race = :race AND p.finishingPosition IS NOT NULL AND p.finishingPosition > 0'
        )
            ->setParameter('race', $race)
            ->getResult();

        $actualPositionsByParticipation = [];
        foreach ($actualRows as $row) {
            if (!$row instanceof Participation || $row->getId() === null) {
                continue;
            }

            $actualPositionsByParticipation[$row->getId()] = (int) $row->getFinishingPosition();
        }

        $predictionRows = [];
        foreach ($predictions as $prediction) {
            $participationId = $prediction->getParticipation()->getId();
            if ($participationId === null) {
                continue;
            }

            $predictionRows[] = [
                'participation_id' => $participationId,
                'rank' => $prediction->getPredictedRank(),
            ];
        }

        $snapshot->clearMetrics();

        if ($predictionRows === [] || $actualPositionsByParticipation === []) {
            $snapshot
                ->setComparisonStatus(PronosticSnapshot::STATUS_PENDING)
                ->setComparableEntries(0)
                ->setComparedAt(null);

            $this->entityManager->flush();

            return;
        }

        $metrics = $this->metricCalculator->calculate($predictionRows, $actualPositionsByParticipation);

        $snapshot
            ->setComparableEntries((int) $metrics['comparable_entries'])
            ->setComparedAt(new \DateTimeImmutable())
            ->setComparisonStatus($metrics['comparable_entries'] >= $snapshot->getTotalEntries()
                ? PronosticSnapshot::STATUS_COMPARED
                : PronosticSnapshot::STATUS_PARTIAL);

        $this->persistMetric($snapshot, PronosticMetric::TYPE_TOP1_ACCURACY, $metrics['top1_accuracy']);
        $this->persistMetric($snapshot, PronosticMetric::TYPE_TOP3_HIT_RATE, $metrics['top3_hit_rate']);
        $this->persistMetric($snapshot, PronosticMetric::TYPE_MEAN_RANK_ERROR, $metrics['mean_rank_error']);
        $this->persistMetric($snapshot, PronosticMetric::TYPE_NDCG_AT_5, $metrics['ndcg_at_5']);

        $this->entityManager->flush();
    }

    private function persistMetric(PronosticSnapshot $snapshot, string $metricType, float $metricValue): void
    {
        $snapshot->addMetric(
            (new PronosticMetric())
                ->setMetricType($metricType)
                ->setMetricValue($metricValue)
                ->setCalculatedAt(new \DateTimeImmutable())
        );
    }
}

