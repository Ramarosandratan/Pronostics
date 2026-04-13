<?php

namespace App\Service;

use App\Entity\Hippodrome;
use App\Entity\Horse;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\Race;
use App\Entity\ScrapeImportState;
use App\Exception\ImportException;
use Doctrine\ORM\EntityManagerInterface;

final class ScrapedRaceImportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PronosticComparisonService $comparisonService,
    ) {
    }

    /**
     * @param array{
     *   race?: array<string, mixed>,
     *   participants?: array<int, array<string, mixed>>
     * } $payload
     *
     * @return array<string, int>
     */
    public function import(array $payload, bool $dryRun = false, bool $forceReimport = false): array
    {
        $importContext = $this->buildImportContext($payload);

        if (
            $importContext['hippodrome_name'] === null
            || $importContext['meeting_number'] === null
            || $importContext['race_number'] === null
            || !$importContext['race_date'] instanceof \DateTimeImmutable
        ) {
            throw new ImportException('Donnees course invalides: hippodrome, meeting_number, race_number et race_date sont requis.');
        }

        $skipStats = $this->resolveSkipStats($importContext, $dryRun, $forceReimport);
        if (is_array($skipStats)) {
            return $skipStats;
        }

        $stats = [
            'rows_total' => count($importContext['participants']),
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'races_created' => 0,
            'horses_created' => 0,
            'persons_created' => 0,
            'error_count' => 0,
        ];

        $horseCache = [];
        $personCache = [];

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $hippodrome = $this->findOrCreateHippodrome($importContext['hippodrome_name']);
            $race = $this->findOrCreateRace(
                $importContext['race_data'],
                $hippodrome,
                $importContext['meeting_number'],
                $importContext['race_number'],
                $importContext['race_date'],
                $importContext['source_date_code'],
                $stats
            );
            $this->processParticipants($race, $importContext['participants'], $stats, $horseCache, $personCache);

            $this->entityManager->flush();

            if ($dryRun) {
                $connection->rollBack();
            } else {
                $this->upsertImportState(
                    $importContext['race_date'],
                    $importContext['meeting_number'],
                    $importContext['race_number'],
                    $importContext['payload_hash']
                );
                $this->entityManager->flush();

                $connection->commit();
                $this->comparisonService->compareRace($race);
            }
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw new ImportException('Erreur import scraping: '.$exception->getMessage(), 0, $exception);
        }

        return $stats;
    }

    public function isRaceAlreadyImported(
        \DateTimeImmutable $raceDate,
        int $meetingNumber,
        int $raceNumber
    ): bool {
        $race = $this->entityManager->getRepository(Race::class)->findOneBy([
            'raceDate' => $raceDate,
            'meetingNumber' => $meetingNumber,
            'raceNumber' => $raceNumber,
        ]);

        if (!$race instanceof Race) {
            return false;
        }

        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Participation::class, 'p')
            ->where('p.race = :race')
            ->setParameter('race', $race)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @return array<string, int>
     */
    private function buildSkippedStats(int $rowsTotal): array
    {
        return [
            'rows_total' => $rowsTotal,
            'rows_imported' => 0,
            'rows_skipped' => $rowsTotal,
            'races_created' => 0,
            'horses_created' => 0,
            'persons_created' => 0,
            'error_count' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *   race_data: array<string, mixed>,
     *   participants: array<int, array<string, mixed>>,
     *   hippodrome_name: ?string,
     *   meeting_number: ?int,
     *   race_number: ?int,
     *   race_date: ?\DateTimeImmutable,
     *   source_date_code: ?string,
     *   payload_hash: string
     * }
     */
    private function buildImportContext(array $payload): array
    {
        $raceData = is_array($payload['race'] ?? null) ? $payload['race'] : [];
        $participants = is_array($payload['participants'] ?? null) ? $payload['participants'] : [];
        $raceDate = $this->toDate($raceData['race_date'] ?? null);
        $sourceDateCode = null;
        $sourceDateCodeRaw = $this->stringOrNull($raceData['source_date_code'] ?? null);
        if ($sourceDateCodeRaw !== null) {
            $sourceDateCodeDigits = preg_replace('/\D+/', '', $sourceDateCodeRaw);
            if (is_string($sourceDateCodeDigits) && strlen($sourceDateCodeDigits) === 8) {
                $sourceDateCode = $sourceDateCodeDigits;
            }
        }
        if ($sourceDateCode === null && $raceDate instanceof \DateTimeImmutable) {
            $sourceDateCode = $raceDate->format('Ymd');
        }

        return [
            'race_data' => $raceData,
            'participants' => $participants,
            'hippodrome_name' => $this->stringOrNull($raceData['hippodrome'] ?? null),
            'meeting_number' => $this->toInt($raceData['meeting_number'] ?? null),
            'race_number' => $this->toInt($raceData['race_number'] ?? null),
            'race_date' => $raceDate,
            'source_date_code' => $sourceDateCode,
            'payload_hash' => $this->buildPayloadHash($payload),
        ];
    }

    /**
     * @param array{
     *   participants: array<int, array<string, mixed>>,
     *   meeting_number: int,
     *   race_number: int,
    *   race_date: \DateTimeImmutable,
     *   payload_hash: string
     * } $importContext
     *
     * @return array<string, int>|null
     */
    private function resolveSkipStats(array $importContext, bool $dryRun, bool $forceReimport): ?array
    {
        $skipStats = null;

        if (!$forceReimport) {
            $raceDate = $importContext['race_date'];
            $meetingNumber = $importContext['meeting_number'];
            $raceNumber = $importContext['race_number'];
            $state = $this->findImportState($raceDate, $meetingNumber, $raceNumber);

            if ($state instanceof ScrapeImportState && $state->getPayloadHash() === $importContext['payload_hash']) {
                $skipStats = $this->buildSkippedStats(count($importContext['participants']));
            } elseif (!$state instanceof ScrapeImportState && $this->isRaceAlreadyImported($raceDate, $meetingNumber, $raceNumber)) {
                if (!$dryRun) {
                    $this->upsertImportState($raceDate, $meetingNumber, $raceNumber, $importContext['payload_hash']);
                    $this->entityManager->flush();
                }

                $skipStats = $this->buildSkippedStats(count($importContext['participants']));
            }
        }

        return $skipStats;
    }

    private function findImportState(
        \DateTimeImmutable $raceDate,
        int $meetingNumber,
        int $raceNumber
    ): ?ScrapeImportState {
        $state = $this->entityManager->getRepository(ScrapeImportState::class)->findOneBy([
            'raceDate' => $raceDate,
            'meetingNumber' => $meetingNumber,
            'raceNumber' => $raceNumber,
        ]);

        return $state instanceof ScrapeImportState ? $state : null;
    }

    private function upsertImportState(
        \DateTimeImmutable $raceDate,
        int $meetingNumber,
        int $raceNumber,
        string $payloadHash
    ): void {
        $state = $this->findImportState($raceDate, $meetingNumber, $raceNumber);
        if (!$state instanceof ScrapeImportState) {
            $state = (new ScrapeImportState())
                ->setRaceDate($raceDate)
                ->setMeetingNumber($meetingNumber)
                ->setRaceNumber($raceNumber);
            $this->entityManager->persist($state);
        }

        $state
            ->setPayloadHash($payloadHash)
            ->setLastImportedAt(new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildPayloadHash(array $payload): string
    {
        $normalized = $this->normalizeForHash($payload);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            throw new ImportException('Impossible de calculer la signature du payload.');
        }

        return hash('sha256', $encoded);
    }

    private function normalizeForHash(mixed $value): mixed
    {
        $normalized = null;

        if (is_array($value)) {
            if (array_is_list($value)) {
                $normalized = array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
            } else {
                ksort($value);

                foreach ($value as $key => $item) {
                    $value[$key] = $this->normalizeForHash($item);
                }

                $normalized = $value;
            }
        } elseif (is_string($value)) {
            $normalized = trim($value);
        } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            $normalized = $value;
        } else {
            $normalized = (string) $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $raceData
     * @param array<string, int> $stats
     */
    private function findOrCreateRace(
        array $raceData,
        Hippodrome $hippodrome,
        int $meetingNumber,
        int $raceNumber,
        \DateTimeImmutable $raceDate,
        ?string $sourceDateCode,
        array &$stats
    ): Race {
        $race = $this->entityManager->getRepository(Race::class)->findOneBy([
            'raceDate' => $raceDate,
            'hippodrome' => $hippodrome,
            'meetingNumber' => $meetingNumber,
            'raceNumber' => $raceNumber,
        ]);

        if (!$race instanceof Race) {
            $race = (new Race())
                ->setRaceDate($raceDate)
                ->setHippodrome($hippodrome)
                ->setMeetingNumber($meetingNumber)
                ->setRaceNumber($raceNumber)
                ->setSourceDateCode($sourceDateCode ?? $raceDate->format('Ymd'));
            $this->entityManager->persist($race);
            ++$stats['races_created'];
        }

        $race->setSourceDateCode($sourceDateCode ?? $raceDate->format('Ymd'));

        $race->setDiscipline($this->stringOrNull($raceData['discipline'] ?? null));
        $autostart = filter_var($raceData['autostart'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        $race
            ->setDistanceMeters($this->toInt($raceData['distance_meters'] ?? null))
            ->setAllocation($this->toBigIntString($raceData['allocation'] ?? null))
            ->setCategory($this->stringOrNull($raceData['category'] ?? null))
            ->setRaceTime($this->stringOrNull($raceData['race_time'] ?? null))
            ->setTrackType($this->stringOrNull($raceData['track_type'] ?? null))
            ->setTrackRope($this->stringOrNull($raceData['track_rope'] ?? null))
            ->setAutostart(is_bool($autostart) ? $autostart : null);

        return $race;
    }

    /**
     * @param array<string, int> $stats
     * @param array<string, Horse> $cache
     */
    private function findOrCreateHorse(string $name, ?string $sex, array &$stats, array &$cache): Horse
    {
        $cacheKey = strtoupper($name);
        if (isset($cache[$cacheKey]) && $cache[$cacheKey] instanceof Horse) {
            $horse = $cache[$cacheKey];
            if ($sex !== null && $horse->getSex() === null) {
                $horse->setSex($sex);
            }

            return $horse;
        }

        $horse = $this->entityManager->getRepository(Horse::class)->findOneBy(['name' => $name]);
        if ($horse instanceof Horse) {
            if ($sex !== null && $horse->getSex() === null) {
                $horse->setSex($sex);
            }

            $cache[$cacheKey] = $horse;

            return $horse;
        }

        $horse = (new Horse())
            ->setName($name)
            ->setSex($sex);

        $this->entityManager->persist($horse);
        ++$stats['horses_created'];
        $cache[$cacheKey] = $horse;

        return $horse;
    }

    /**
     * @param array<string, int> $stats
     * @param array<string, Person> $cache
     */
    private function findOrCreatePerson(mixed $value, array &$stats, array &$cache): ?Person
    {
        $name = $this->stringOrNull($value);
        if ($name === null) {
            return null;
        }

        $cacheKey = strtoupper($name);
        $person = $cache[$cacheKey] ?? null;

        if (!$person instanceof Person) {
            $person = $this->entityManager->getRepository(Person::class)->findOneBy(['name' => $name]);
        }

        if (!$person instanceof Person) {
            $person = (new Person())->setName($name);
            $this->entityManager->persist($person);
            ++$stats['persons_created'];
        }

        $cache[$cacheKey] = $person;

        return $person;
    }

    private function findOrCreateHippodrome(string $name): Hippodrome
    {
        $normalized = strtoupper(trim($name));
        $hippodrome = $this->entityManager->getRepository(Hippodrome::class)->findOneBy(['name' => $normalized]);
        if ($hippodrome instanceof Hippodrome) {
            return $hippodrome;
        }

        $hippodrome = (new Hippodrome())->setName($normalized);
        $this->entityManager->persist($hippodrome);

        return $hippodrome;
    }

    /**
     * @param array<int, array<string, mixed>> $participants
     * @param array<string, int> $stats
     * @param array<string, Horse> $horseCache
     * @param array<string, Person> $personCache
     */
    private function processParticipants(
        Race $race,
        array $participants,
        array &$stats,
        array &$horseCache,
        array &$personCache
    ): void {
        foreach ($participants as $row) {
            if (!is_array($row)) {
                ++$stats['rows_skipped'];
                ++$stats['error_count'];
                continue;
            }

            $horseName = $this->stringOrNull($row['horse_name'] ?? null);
            if ($horseName === null) {
                ++$stats['rows_skipped'];
                continue;
            }

            $horse = $this->findOrCreateHorse($horseName, $this->stringOrNull($row['sex'] ?? null), $stats, $horseCache);
            $existingParticipation = $this->entityManager->getRepository(Participation::class)->findOneBy([
                'race' => $race,
                'horse' => $horse,
            ]);

            if ($existingParticipation instanceof Participation) {
                $existingParticipation
                    ->setSaddleNumber($this->toInt($row['saddle_number'] ?? null))
                    ->setFinishingPosition($this->toInt($row['finishing_position'] ?? null))
                    ->setAgeAtRace($this->toInt($row['age_at_race'] ?? null))
                    ->setDistanceOrWeight($this->toFloat($row['distance_or_weight'] ?? null))
                    ->setShoeingOrDraw($this->stringOrNull($row['shoeing_or_draw'] ?? null))
                    ->setPerformanceIndicator($this->stringOrNull($row['performance_indicator'] ?? null))
                    ->setOdds($this->toFloat($row['odds'] ?? null))
                    ->setMusic($this->stringOrNull($row['music'] ?? null))
                    ->setCareerEarnings($this->toBigIntString($row['career_earnings'] ?? null));

                $existingParticipation->setJockey($this->findOrCreatePerson($row['jockey'] ?? null, $stats, $personCache));
                $existingParticipation->setTrainer($this->findOrCreatePerson($row['trainer'] ?? null, $stats, $personCache));
                $existingParticipation->setOwner($this->findOrCreatePerson($row['owner'] ?? null, $stats, $personCache));

                ++$stats['rows_imported'];
                continue;
            }

            $participation = (new Participation())
                ->setRace($race)
                ->setHorse($horse)
                ->setSaddleNumber($this->toInt($row['saddle_number'] ?? null))
                ->setFinishingPosition($this->toInt($row['finishing_position'] ?? null))
                ->setAgeAtRace($this->toInt($row['age_at_race'] ?? null))
                ->setDistanceOrWeight($this->toFloat($row['distance_or_weight'] ?? null))
                ->setShoeingOrDraw($this->stringOrNull($row['shoeing_or_draw'] ?? null))
                ->setPerformanceIndicator($this->stringOrNull($row['performance_indicator'] ?? null))
                ->setOdds($this->toFloat($row['odds'] ?? null))
                ->setMusic($this->stringOrNull($row['music'] ?? null))
                ->setCareerEarnings($this->toBigIntString($row['career_earnings'] ?? null));

            $participation->setJockey($this->findOrCreatePerson($row['jockey'] ?? null, $stats, $personCache));
            $participation->setTrainer($this->findOrCreatePerson($row['trainer'] ?? null, $stats, $personCache));
            $participation->setOwner($this->findOrCreatePerson($row['owner'] ?? null, $stats, $personCache));

            $this->entityManager->persist($participation);
            ++$stats['rows_imported'];
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function toInt(mixed $value): ?int
    {
        $normalized = $this->stringOrNull($value);
        if ($normalized === null) {
            return null;
        }

        if (preg_match('/-?\d+/', $normalized, $match) !== 1) {
            return null;
        }

        return (int) $match[0];
    }

    private function toFloat(mixed $value): ?float
    {
        $normalized = $this->stringOrNull($value);
        if ($normalized === null) {
            return null;
        }

        $normalized = str_replace(',', '.', $normalized);
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function toBigIntString(mixed $value): ?string
    {
        $normalized = $this->stringOrNull($value);
        if ($normalized === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $normalized);

        return $digits !== '' ? $digits : null;
    }

    private function toDate(mixed $value): ?\DateTimeImmutable
    {
        $normalized = $this->stringOrNull($value);
        if ($normalized === null) {
            return null;
        }

        $resolved = null;
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Ymd'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $normalized);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $normalized) {
                $resolved = $date;
                break;
            }
        }

        if ($resolved instanceof \DateTimeImmutable) {
            return $resolved;
        }

        foreach ([DATE_ATOM, 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $normalized);
            if ($date instanceof \DateTimeImmutable) {
                $resolved = $date->setTime(0, 0, 0);
                break;
            }
        }

        return $resolved;
    }
}

