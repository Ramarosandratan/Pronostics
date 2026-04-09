<?php

namespace App\Service;

use App\Entity\Participation;
use App\Entity\PronosticPrediction;
use App\Entity\PronosticSnapshot;
use App\Entity\Race;
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
    public function capturePreRaceSnapshot(Race $race): array
    {
        $rankings = $this->scoringService->scoreRace($race);
        if ($rankings === []) {
            return [];
        }

        $snapshot = $this->entityManager->getRepository(PronosticSnapshot::class)->findOneBy([
            'race' => $race,
            'snapshotType' => PronosticSnapshot::TYPE_PRE_RACE,
        ]);

        if (!$snapshot instanceof PronosticSnapshot) {
            $snapshot = (new PronosticSnapshot())
                ->setRace($race)
                ->setSnapshotType(PronosticSnapshot::TYPE_PRE_RACE);

            $this->entityManager->persist($snapshot);
        }

        $snapshot
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setComparisonStatus(PronosticSnapshot::STATUS_PENDING)
            ->setComparedAt(null)
            ->setComparableEntries(0)
            ->setTotalEntries(count($rankings))
            ->clearPredictions()
            ->clearMetrics();

        $participations = $this->entityManager->createQuery(
            'SELECT p FROM App\\Entity\\Participation p WHERE p.race = :race'
        )
            ->setParameter('race', $race)
            ->getResult();

        $participationById = [];
        foreach ($participations as $participation) {
            if (!$participation instanceof Participation || $participation->getId() === null) {
                continue;
            }

            $participationById[$participation->getId()] = $participation;
        }

        foreach ($rankings as $row) {
            $participationId = isset($row['participation_id']) ? (int) $row['participation_id'] : 0;
            if (!isset($participationById[$participationId])) {
                continue;
            }

            $prediction = (new PronosticPrediction())
                ->setParticipation($participationById[$participationId])
                ->setPredictedRank((int) ($row['rank'] ?? 0))
                ->setPredictedScore((float) ($row['score'] ?? 0.0))
                ->setSubScores(isset($row['sub_scores']) && is_array($row['sub_scores']) ? $row['sub_scores'] : null);

            $snapshot->addPrediction($prediction);
        }

        $this->entityManager->flush();

        return $rankings;
    }
}
