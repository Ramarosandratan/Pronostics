<?php

namespace App\Service;

use App\Entity\Participation;
use App\Entity\PronosticPrediction;
use App\Entity\PronosticSnapshot;
use App\Entity\Race;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

class PronosticSnapshotService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PronosticScoringService $scoringService,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function capturePreRaceSnapshot(Race $race, ?string $mode = null): array
    {
        $configuration = $this->scoringService->resolveScoringConfiguration($mode);
        $rankings = $this->scoringService->scoreRace($race, $configuration['mode']);
        if ($rankings === []) {
            return [];
        }

        try {
            $snapshot = $this->findOrCreateSnapshot($race);
            $this->prepareSnapshot($snapshot, count($rankings), $configuration['mode'], $configuration['weights']);
            $participationById = $this->loadParticipationMap($race);
            $this->attachPredictions($snapshot, $rankings, $participationById);

            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            if (!self::isMissingTableException($exception)) {
                throw $exception;
            }
        }

        return $rankings;
    }

    private function findOrCreateSnapshot(Race $race): PronosticSnapshot
    {
        $snapshot = $this->entityManager->getRepository(PronosticSnapshot::class)->findOneBy([
            'race' => $race,
            'snapshotType' => PronosticSnapshot::TYPE_PRE_RACE,
        ]);

        if ($snapshot instanceof PronosticSnapshot) {
            return $snapshot;
        }

        $snapshot = (new PronosticSnapshot())
            ->setRace($race)
            ->setSnapshotType(PronosticSnapshot::TYPE_PRE_RACE);

        $this->entityManager->persist($snapshot);

        return $snapshot;
    }

    /**
     * @param array<string, float> $weights
     */
    private function prepareSnapshot(PronosticSnapshot $snapshot, int $totalEntries, string $mode, array $weights): void
    {
        $snapshot
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setComparisonStatus(PronosticSnapshot::STATUS_PENDING)
            ->setComparedAt(null)
            ->setComparableEntries(0)
            ->setTotalEntries($totalEntries)
            ->setScoringMode($mode)
            ->setScoringWeights($weights)
            ->clearPredictions()
            ->clearMetrics();
    }

    /**
     * @return array<int, Participation>
     */
    private function loadParticipationMap(Race $race): array
    {
        $participations = $this->entityManager->createQuery(
            'SELECT p FROM App\\Entity\\Participation p WHERE p.race = :race'
        )
            ->setParameter('race', $race)
            ->getResult();

        $map = [];
        foreach ($participations as $participation) {
            if (!$participation instanceof Participation || $participation->getId() === null) {
                continue;
            }

            $map[$participation->getId()] = $participation;
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rankings
     * @param array<int, Participation> $participationById
     */
    private function attachPredictions(PronosticSnapshot $snapshot, array $rankings, array $participationById): void
    {
        foreach ($rankings as $row) {
            $participationId = isset($row['participation_id']) ? (int) $row['participation_id'] : 0;
            if (!isset($participationById[$participationId])) {
                continue;
            }

            $snapshot->addPrediction(
                (new PronosticPrediction())
                    ->setParticipation($participationById[$participationId])
                    ->setPredictedRank((int) ($row['rank'] ?? 0))
                    ->setPredictedScore((float) ($row['score'] ?? 0.0))
                    ->setSubScores(isset($row['sub_scores']) && is_array($row['sub_scores']) ? $row['sub_scores'] : null)
            );
        }
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
