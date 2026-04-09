<?php

namespace App\Service;

use App\Entity\Horse;
use App\Entity\HorseAlias;
use App\Entity\Hippodrome;
use App\Entity\ImportError;
use App\Entity\ImportSession;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\PersonAlias;
use App\Entity\Race;
use App\Exception\ImportException;
use Doctrine\ORM\EntityManagerInterface;
use Shuchkin\SimpleXLSX;

class ExcelImportService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, int>
     */
    public function import(string $filePath, bool $dryRun = false): array
    {
        $rows = $this->loadRows($filePath);
        if (count($rows) < 2) {
            return [
                'rows_total' => 0,
                'rows_imported' => 0,
                'rows_skipped' => 0,
                'races_created' => 0,
                'horses_created' => 0,
                'persons_created' => 0,
                'error_count' => 0,
                'import_session_id' => 0,
            ];
        }

        $indexes = $this->resolveAndValidateIndexes($rows);

        $stats = [
            'rows_total' => count($rows) - 1,
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'races_created' => 0,
            'horses_created' => 0,
            'persons_created' => 0,
            'error_count' => 0,
            'import_session_id' => 0,
        ];
        $importSession = null;
        if (!$dryRun) {
            $importSession = (new ImportSession())
                ->setFileName(basename($filePath))
                ->setStatus('running')
                ->setTotalRows($stats['rows_total']);

            $this->entityManager->persist($importSession);
        }
        $context = [
            'raceCache' => [],
            'horseCache' => [],
            'personCache' => [],
            'participationSeen' => [],
        ];

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $line = 0;

        try {
            $line = $this->processRows($rows, $indexes, $importSession, $stats, $context);

            if ($importSession instanceof ImportSession) {
                $importSession
                    ->setRowsImported($stats['rows_imported'])
                    ->setRowsSkipped($stats['rows_skipped'])
                    ->setErrorCount($stats['error_count'])
                    ->setStatus($stats['error_count'] > 0 ? 'failed_with_errors' : 'completed');
            }

            $this->entityManager->flush();
            $stats['import_session_id'] = (int) ($importSession?->getId() ?? 0);

            if ($dryRun) {
                $connection->rollBack();

                return $stats;
            }

            $connection->commit();

            return $stats;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            if ($importSession instanceof ImportSession) {
                try {
                    $importSession
                        ->setRowsImported($stats['rows_imported'])
                        ->setRowsSkipped($stats['rows_skipped'])
                        ->setErrorCount($stats['error_count'])
                        ->setStatus('failed');

                    $this->entityManager->persist($importSession);
                    $this->entityManager->flush();
                } catch (\Throwable) {
                    // Ignore secondary failure to preserve original exception context.
                }
            }

            throw new ImportException(
                sprintf('Erreur d\'import a la ligne %d: %s', $line ?? 0, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function loadRows(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new ImportException(sprintf('Fichier introuvable: %s', $filePath));
        }

        $xlsx = SimpleXLSX::parse($filePath);
        if ($xlsx === false) {
            throw new ImportException(sprintf('Impossible de parser le fichier XLSX: %s', SimpleXLSX::parseError()));
        }

        $rowsRaw = $xlsx->rows();

        return is_array($rowsRaw) ? $rowsRaw : iterator_to_array($rowsRaw, false);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function resolveAndValidateIndexes(array $rows): array
    {
        $header = array_map(static fn ($value): string => (string) $value, $rows[0]);
        $indexes = $this->resolveHeaderIndexes($header);

        if (!isset($indexes['hippodrome']) || !isset($indexes['horse_name'])) {
            $indexes = array_merge($indexes, $this->resolveFallbackIndexesByPosition($rows));
        }

        foreach (['hippodrome', 'horse_name'] as $field) {
            if (!isset($indexes[$field])) {
                throw new ImportException(sprintf('Colonne requise non detectee: %s', $field));
            }
        }

        return $indexes;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, int> $indexes
     * @param array<string, int> $stats
     * @param array{raceCache: array<string, Race>, horseCache: array<string, Horse>, personCache: array<string, Person>, participationSeen: array<string, bool>} $context
     */
    private function processRows(array $rows, array $indexes, ?ImportSession $session, array &$stats, array &$context): int
    {
        $line = 0;

        foreach (array_slice($rows, 1) as $lineNumber => $row) {
            $line = $lineNumber + 2;

            try {
                $this->processRow($row, $line, $indexes, $session, $stats, $context);
            } catch (\Throwable $rowException) {
                ++$stats['rows_skipped'];
                $this->recordImportError(
                    $session,
                    $stats,
                    $line,
                    'row_processing_error',
                    $rowException->getMessage(),
                    $row
                );
            }
        }

        return $line;
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $indexes
     * @param array<string, int> $stats
     * @param array{raceCache: array<string, Race>, horseCache: array<string, Horse>, personCache: array<string, Person>, participationSeen: array<string, bool>} $context
     */
    private function processRow(
        array $row,
        int $line,
        array $indexes,
        ?ImportSession $session,
        array &$stats,
        array &$context
    ): void {
        $horseName = $this->getCell($row, $indexes, 'horse_name');
        $hippodrome = $this->getCell($row, $indexes, 'hippodrome');
        if ($horseName === null || $hippodrome === null) {
            $this->skipRow(
                $session,
                $stats,
                $line,
                'missing_field',
                'Ligne ignoree: hippodrome ou nom du cheval manquant.',
                $row
            );

            return;
        }

        $meetingNumber = $this->toInt($this->getCell($row, $indexes, 'meeting_number'));
        $raceNumber = $this->toInt($this->getCell($row, $indexes, 'race_number'));
        if ($meetingNumber === null || $raceNumber === null) {
            [$meetingNumber, $raceNumber] = ImportValueParser::extractMeetingRaceFromCode(
                $this->getCell($row, $indexes, 'meeting_race_code')
            );
        }

        if ($meetingNumber === null || $raceNumber === null) {
            $this->skipRow(
                $session,
                $stats,
                $line,
                'invalid_format',
                'Ligne ignoree: numero reunion/course invalide.',
                $row
            );

            return;
        }

        $sourceDateCode = $this->getCell($row, $indexes, 'source_date_code');
        $raceDate = $this->parseDate($this->getCell($row, $indexes, 'race_date'));
        $raceInfo = [
            'hippodrome' => $hippodrome,
            'meetingNumber' => $meetingNumber,
            'raceNumber' => $raceNumber,
            'sourceDateCode' => $sourceDateCode,
            'raceDate' => $raceDate,
        ];
        [$raceIdentity, $race] = $this->resolveRace(
            $row,
            $indexes,
            $context,
            $stats,
            $raceInfo
        );

        $sexAge = $this->parseSexAge($this->getCell($row, $indexes, 'sex_age'));
        [$horseKey, $horse] = $this->resolveHorse($context, $stats, $horseName, $sexAge['sex']);
        $participationKey = $raceIdentity . '|' . $horseKey;
        $canCreateParticipation = true;

        if (isset($context['participationSeen'][$participationKey])) {
            $this->skipRow(
                $session,
                $stats,
                $line,
                'duplicate_warning',
                'Ligne ignoree: participation dupliquee dans le fichier.',
                $row,
                'warning'
            );
            $canCreateParticipation = false;
        } else {
            $existingParticipation = $this->entityManager->getRepository(Participation::class)->findOneBy([
                'race' => $race,
                'horse' => $horse,
            ]);

            if ($existingParticipation instanceof Participation) {
                $context['participationSeen'][$participationKey] = true;
                $this->skipRow(
                    $session,
                    $stats,
                    $line,
                    'duplicate_warning',
                    'Ligne ignoree: participation deja en base.',
                    $row,
                    'warning'
                );
                $canCreateParticipation = false;
            }
        }

        if ($canCreateParticipation) {
            $jockey = $this->findOrCreatePerson($this->getCell($row, $indexes, 'jockey'), $context['personCache'], $stats);
            $trainer = $this->findOrCreatePerson($this->getCell($row, $indexes, 'trainer'), $context['personCache'], $stats);
            $owner = $this->findOrCreatePerson($this->getCell($row, $indexes, 'owner'), $context['personCache'], $stats);

            $participation = (new Participation())
                ->setRace($race)
                ->setHorse($horse)
                ->setJockey($jockey)
                ->setTrainer($trainer)
                ->setOwner($owner)
                ->setSaddleNumber($this->toInt($this->getCell($row, $indexes, 'saddle_number')))
                ->setFinishingPosition($this->toInt($this->getCell($row, $indexes, 'finishing_position')))
                ->setAgeAtRace($sexAge['age'])
                ->setDistanceOrWeight($this->toFloat($this->getCell($row, $indexes, 'distance_or_weight')))
                ->setShoeingOrDraw($this->getCell($row, $indexes, 'shoeing_or_draw'))
                ->setPerformanceIndicator($this->getCell($row, $indexes, 'performance_indicator'))
                ->setOdds($this->toFloat($this->getCell($row, $indexes, 'odds')))
                ->setMusic($this->getCell($row, $indexes, 'music'))
                ->setCareerEarnings($this->toBigInt($this->getCell($row, $indexes, 'career_earnings')));

            $this->entityManager->persist($participation);
            $context['participationSeen'][$participationKey] = true;
            ++$stats['rows_imported'];
        }
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $indexes
     * @param array<string, int> $stats
     * @param array{raceCache: array<string, Race>, horseCache: array<string, Horse>, personCache: array<string, Person>, participationSeen: array<string, bool>} $context
     * @param array{hippodrome: string, meetingNumber: int, raceNumber: int, sourceDateCode: ?string, raceDate: ?\DateTimeImmutable} $raceInfo
     *
     * @return array{0: string, 1: Race}
     */
    private function resolveRace(
        array $row,
        array $indexes,
        array &$context,
        array &$stats,
        array $raceInfo
    ): array {
        $hippodromeUppercase = strtoupper($raceInfo['hippodrome']);
        
        $raceIdentity = sprintf(
            '%s|%s|%d|%d',
            $raceInfo['sourceDateCode'] ?? '-',
            $hippodromeUppercase,
            $raceInfo['meetingNumber'],
            $raceInfo['raceNumber']
        );

        if (!isset($context['raceCache'][$raceIdentity])) {
            $horseRepository = $this->entityManager->getRepository(Hippodrome::class);
            $hippodrome = $horseRepository->findOneBy(['name' => $hippodromeUppercase]);

            if (!$hippodrome instanceof Hippodrome) {
                $hippodrome = (new Hippodrome())->setName($hippodromeUppercase);
                $this->entityManager->persist($hippodrome);
            }

            $race = $this->entityManager->getRepository(Race::class)->findOneBy([
                'sourceDateCode' => $raceInfo['sourceDateCode'],
                'hippodrome' => $hippodrome,
                'meetingNumber' => $raceInfo['meetingNumber'],
                'raceNumber' => $raceInfo['raceNumber'],
            ]);

            if (!$race instanceof Race) {
                $race = (new Race())
                    ->setHippodrome($hippodrome)
                    ->setMeetingNumber($raceInfo['meetingNumber'])
                    ->setRaceNumber($raceInfo['raceNumber'])
                    ->setSourceDateCode($raceInfo['sourceDateCode'])
                    ->setRaceDate($raceInfo['raceDate'])
                    ->setDiscipline($this->getCell($row, $indexes, 'discipline'));

                $this->entityManager->persist($race);
                ++$stats['races_created'];
            }

            $context['raceCache'][$raceIdentity] = $race;
        }

        return [$raceIdentity, $context['raceCache'][$raceIdentity]];
    }

    /**
     * @param array<string, int> $stats
     * @param array{raceCache: array<string, Race>, horseCache: array<string, Horse>, personCache: array<string, Person>, participationSeen: array<string, bool>} $context
     *
     * @return array{0: string, 1: Horse}
     */
    private function resolveHorse(array &$context, array &$stats, string $horseName, ?string $sex): array
    {
        $horseKey = strtoupper($horseName);
        $canonicalHorseName = strtoupper($this->normalize($horseName));

        if (!isset($context['horseCache'][$horseKey])) {
            $horse = $this->entityManager->getRepository(Horse::class)->findOneBy(['name' => $horseName]);

            if (!$horse instanceof Horse) {
                $alias = $this->entityManager->getRepository(HorseAlias::class)->findOneBy([
                    'canonicalForm' => $canonicalHorseName,
                ]);
                if ($alias instanceof HorseAlias) {
                    $horse = $alias->getHorse();
                }
            }

            if (!$horse instanceof Horse) {
                $horse = (new Horse())
                    ->setName($horseName)
                    ->setSex($sex);

                $this->entityManager->persist($horse);
                ++$stats['horses_created'];
            } elseif ($horse->getSex() === null && $sex !== null) {
                $horse->setSex($sex);
            }

            $existingAlias = $this->entityManager->getRepository(HorseAlias::class)->findOneBy([
                'canonicalForm' => $canonicalHorseName,
            ]);
            if (!$existingAlias instanceof HorseAlias) {
                $this->entityManager->persist(
                    (new HorseAlias())
                        ->setHorse($horse)
                        ->setOriginalForm($horseName)
                        ->setCanonicalForm($canonicalHorseName)
                );
            }

            $context['horseCache'][$horseKey] = $horse;
        }

        return [$horseKey, $context['horseCache'][$horseKey]];
    }

    /**
     * @param array<string, int> $stats
     * @param array<int, mixed>|null $row
     */
    private function skipRow(
        ?ImportSession $session,
        array &$stats,
        int $line,
        string $errorType,
        string $message,
        ?array $row = null,
        string $severity = 'error'
    ): void {
        ++$stats['rows_skipped'];
        $this->recordImportError($session, $stats, $line, $errorType, $message, $row, $severity);
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $indexes
     */
    private function getCell(array $row, array $indexes, string $field): ?string
    {
        if (!isset($indexes[$field])) {
            return null;
        }

        $index = $indexes[$field];
        if (!array_key_exists($index, $row)) {
            return null;
        }

        $value = trim((string) $row[$index]);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<int, string> $header
     *
     * @return array<string, int>
     */
    private function resolveHeaderIndexes(array $header): array
    {
        $normalized = array_map(fn (string $value): string => $this->normalize($value), $header);

        $aliases = [
            'race_date' => ['date', 'date course'],
            'source_date_code' => ['date code', 'date (code)', 'code date'],
            'hippodrome' => ['reunion et hippodrome', 'hippodrome', 'reunion', 'lieu'],
            'meeting_number' => ['meeting number', 'numero reunion', 'reunion number', 'reunion no', 'r'],
            'race_number' => ['race number', 'numero course', 'course number', 'course no', 'c'],
            'meeting_race_code' => ['identifiants de la course', 'identifiant course', 'id course', 'rxcx'],
            'discipline' => ['discipline', 'specialite'],
            'finishing_position' => ['classement', 'place finale', 'place'],
            'saddle_number' => ['numero pmu', 'numero dossard', 'dossard', 'numero'],
            'horse_name' => ['nom du cheval', 'cheval', 'nom'],
            'sex_age' => ['sexe et age', 'sexe/age', 'sex age'],
            'distance_or_weight' => ['condition physique', 'distance', 'poids', 'distance ou poids', 'distance/poids'],
            'shoeing_or_draw' => ['equipement position', 'equipement', 'position', 'ferrure', 'corde'],
            'jockey' => ['jockey driver', 'jockey', 'driver'],
            'trainer' => ['entraineur', 'trainer'],
            'owner' => ['proprietaire', 'owner'],
            'performance_indicator' => ['record ou oeilleres', 'record', 'oeilleres'],
            'music' => ['musique'],
            'odds' => ['cote finale pmu', 'cote', 'odds'],
            'career_earnings' => ['gains en carriere', 'gains'],
        ];

        $indexes = [];

        foreach ($aliases as $field => $candidates) {
            foreach ($normalized as $index => $headerLabel) {
                foreach ($candidates as $candidate) {
                    $normalizedCandidate = $this->normalize($candidate);
                    if ($headerLabel === $normalizedCandidate || str_contains($headerLabel, $normalizedCandidate)) {
                        $indexes[$field] = $index;
                        continue 3;
                    }
                }
            }
        }

        return $indexes;
    }

    /**
     * Fallback pour les exports PMU bruts sans entete exploitable.
     *
     * @param array<int, array<int, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function resolveFallbackIndexesByPosition(array $rows): array
    {
        $sample = $rows[1] ?? [];
        $hippodrome = trim((string) ($sample[0] ?? ''));
        $horseName = trim((string) ($sample[8] ?? ''));

        if ($hippodrome === '' || $horseName === '' || preg_match('/R\s*\d+/i', $hippodrome) !== 1) {
            return [];
        }

        return [
            'hippodrome' => 0,
            'source_date_code' => 1,
            'meeting_race_code' => 2,
            'finishing_position' => 6,
            'saddle_number' => 7,
            'horse_name' => 8,
            'distance_or_weight' => 9,
            'shoeing_or_draw' => 10,
            'sex_age' => 11,
            'jockey' => 12,
            'trainer' => 13,
            'owner' => 14,
            'performance_indicator' => 15,
            'music' => 16,
            'odds' => 21,
            'career_earnings' => 38,
        ];
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function toInt(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!preg_match('/^-?\d+$/', trim($value))) {
            return null;
        }

        return (int) $value;
    }

    private function toFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        if (!is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    private function toBigInt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9-]/', '', $value);
        if ($clean === null || $clean === '' || $clean === '-') {
            return null;
        }

        return $clean;
    }

    /**
     * @return array{sex: ?string, age: ?int}
     */
    private function parseSexAge(?string $value): array
    {
        if ($value === null) {
            return ['sex' => null, 'age' => null];
        }

        if (preg_match('/^([A-Za-z])\s*(\d{1,2})$/', trim($value), $matches) === 1) {
            return [
                'sex' => strtoupper($matches[1]),
                'age' => (int) $matches[2],
            ];
        }

        return ['sex' => null, 'age' => null];
    }

    /**
     * @param array<string, Person> $personCache
     * @param array<string, int> $stats
     */
    private function findOrCreatePerson(?string $name, array &$personCache, array &$stats): ?Person
    {
        if ($name === null) {
            return null;
        }

        $key = strtoupper($name);
        $canonicalName = strtoupper($this->normalize($name));
        if (isset($personCache[$key])) {
            return $personCache[$key];
        }

        $person = $this->entityManager->getRepository(Person::class)->findOneBy(['name' => $name]);

        if (!$person instanceof Person) {
            $alias = $this->entityManager->getRepository(PersonAlias::class)->findOneBy([
                'canonicalForm' => $canonicalName,
            ]);
            if ($alias instanceof PersonAlias) {
                $person = $alias->getPerson();
            }
        }

        if (!$person instanceof Person) {
            $person = (new Person())->setName($name);
            $this->entityManager->persist($person);
            ++$stats['persons_created'];
        }

        $existingAlias = $this->entityManager->getRepository(PersonAlias::class)->findOneBy([
            'canonicalForm' => $canonicalName,
        ]);
        if (!$existingAlias instanceof PersonAlias) {
            $this->entityManager->persist(
                (new PersonAlias())
                    ->setPerson($person)
                    ->setOriginalForm($name)
                    ->setCanonicalForm($canonicalName)
            );
        }

        $personCache[$key] = $person;

        return $person;
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $stats
     * @param array<int, mixed>|null $rowSnapshot
     */
    private function recordImportError(
        ?ImportSession $session,
        array &$stats,
        ?int $rowNumber,
        string $errorType,
        string $message,
        ?array $rowSnapshot = null,
        string $severity = 'error'
    ): void {
        ++$stats['error_count'];

        if (!$session instanceof ImportSession) {
            return;
        }

        $snapshot = null;
        if ($rowSnapshot !== null) {
            $snapshot = array_map(static fn (mixed $value): string => trim((string) $value), $rowSnapshot);
        }

        $error = (new ImportError())
            ->setSession($session)
            ->setRowNumber($rowNumber)
            ->setErrorType($errorType)
            ->setSeverity($severity)
            ->setErrorMessage($message)
            ->setSourceSnapshot($snapshot);

        $this->entityManager->persist($error);
    }
}
