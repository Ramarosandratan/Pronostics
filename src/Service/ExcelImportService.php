<?php

namespace App\Service;

use App\Entity\Horse;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\Race;
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
        if (!is_file($filePath)) {
            throw new \RuntimeException(sprintf('Fichier introuvable: %s', $filePath));
        }

        $xlsx = SimpleXLSX::parse($filePath);

        if ($xlsx === false) {
            throw new \RuntimeException(sprintf('Impossible de parser le fichier XLSX: %s', SimpleXLSX::parseError()));
        }

        $rowsRaw = $xlsx->rows();
        $rows = is_array($rowsRaw) ? $rowsRaw : iterator_to_array($rowsRaw, false);
        if (count($rows) < 2) {
            return [
                'rows_total' => 0,
                'rows_imported' => 0,
                'rows_skipped' => 0,
                'races_created' => 0,
                'horses_created' => 0,
                'persons_created' => 0,
            ];
        }

        $header = array_map(static fn ($value): string => (string) $value, $rows[0]);
        $indexes = $this->resolveHeaderIndexes($header);

        if (!isset($indexes['hippodrome']) || !isset($indexes['horse_name'])) {
            $indexes = array_merge($indexes, $this->resolveFallbackIndexesByPosition($rows));
        }

        $required = ['hippodrome', 'horse_name'];
        foreach ($required as $field) {
            if (!isset($indexes[$field])) {
                throw new \RuntimeException(sprintf('Colonne requise non detectee: %s', $field));
            }
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        $stats = [
            'rows_total' => count($rows) - 1,
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'races_created' => 0,
            'horses_created' => 0,
            'persons_created' => 0,
        ];

        $raceCache = [];
        $horseCache = [];
        $personCache = [];
        $participationSeen = [];
        $line = 0;

        try {
            foreach (array_slice($rows, 1) as $lineNumber => $row) {
                $line = $lineNumber + 2;

                $horseName = $this->getCell($row, $indexes, 'horse_name');
                $hippodrome = $this->getCell($row, $indexes, 'hippodrome');
                if ($horseName === null || $hippodrome === null) {
                    ++$stats['rows_skipped'];
                    continue;
                }

                $meetingNumber = $this->toInt($this->getCell($row, $indexes, 'meeting_number'));
                $raceNumber = $this->toInt($this->getCell($row, $indexes, 'race_number'));

                if ($meetingNumber === null || $raceNumber === null) {
                    [$meetingNumber, $raceNumber] = $this->extractMeetingRaceFromCode(
                        $this->getCell($row, $indexes, 'meeting_race_code')
                    );
                }

                if ($meetingNumber === null || $raceNumber === null) {
                    ++$stats['rows_skipped'];
                    continue;
                }

                $sourceDateCode = $this->getCell($row, $indexes, 'source_date_code');
                $raceDate = $this->parseDate($this->getCell($row, $indexes, 'race_date'));

                $raceIdentity = sprintf(
                    '%s|%s|%d|%d',
                    $sourceDateCode ?? '-',
                    strtoupper($hippodrome),
                    $meetingNumber,
                    $raceNumber
                );

                if (!isset($raceCache[$raceIdentity])) {
                    $race = $this->entityManager->getRepository(Race::class)->findOneBy([
                        'sourceDateCode' => $sourceDateCode,
                        'hippodrome' => strtoupper($hippodrome),
                        'meetingNumber' => $meetingNumber,
                        'raceNumber' => $raceNumber,
                    ]);

                    if (!$race instanceof Race) {
                        $race = (new Race())
                            ->setHippodrome($hippodrome)
                            ->setMeetingNumber($meetingNumber)
                            ->setRaceNumber($raceNumber)
                            ->setSourceDateCode($sourceDateCode)
                            ->setRaceDate($raceDate)
                            ->setDiscipline($this->getCell($row, $indexes, 'discipline'));

                        $this->entityManager->persist($race);
                        ++$stats['races_created'];
                    }

                    $raceCache[$raceIdentity] = $race;
                }

                $sexAge = $this->parseSexAge($this->getCell($row, $indexes, 'sex_age'));
                $horseKey = strtoupper($horseName);

                if (!isset($horseCache[$horseKey])) {
                    $horse = $this->entityManager->getRepository(Horse::class)->findOneBy(['name' => $horseName]);

                    if (!$horse instanceof Horse) {
                        $horse = (new Horse())
                            ->setName($horseName)
                            ->setSex($sexAge['sex']);

                        $this->entityManager->persist($horse);
                        ++$stats['horses_created'];
                    } elseif ($horse->getSex() === null && $sexAge['sex'] !== null) {
                        $horse->setSex($sexAge['sex']);
                    }

                    $horseCache[$horseKey] = $horse;
                }

                $race = $raceCache[$raceIdentity];
                $horse = $horseCache[$horseKey];

                $participationKey = $raceIdentity . '|' . $horseKey;
                if (isset($participationSeen[$participationKey])) {
                    ++$stats['rows_skipped'];
                    continue;
                }

                $existingParticipation = $this->entityManager->getRepository(Participation::class)->findOneBy([
                    'race' => $race,
                    'horse' => $horse,
                ]);

                if ($existingParticipation instanceof Participation) {
                    $participationSeen[$participationKey] = true;
                    ++$stats['rows_skipped'];

                    continue;
                }

                $jockey = $this->findOrCreatePerson($this->getCell($row, $indexes, 'jockey'), $personCache, $stats);
                $trainer = $this->findOrCreatePerson($this->getCell($row, $indexes, 'trainer'), $personCache, $stats);
                $owner = $this->findOrCreatePerson($this->getCell($row, $indexes, 'owner'), $personCache, $stats);

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
                $participationSeen[$participationKey] = true;
                ++$stats['rows_imported'];

                if ($line % 200 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $raceCache = [];
                    $horseCache = [];
                    $personCache = [];
                }
            }

            $this->entityManager->flush();

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

            throw new \RuntimeException(
                sprintf('Erreur d\'import a la ligne %d: %s', $line ?? 0, $exception->getMessage()),
                0,
                $exception
            );
        }
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
        if (isset($personCache[$key])) {
            return $personCache[$key];
        }

        $person = $this->entityManager->getRepository(Person::class)->findOneBy(['name' => $name]);

        if (!$person instanceof Person) {
            $person = (new Person())->setName($name);
            $this->entityManager->persist($person);
            ++$stats['persons_created'];
        }

        $personCache[$key] = $person;

        return $person;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function extractMeetingRaceFromCode(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        if (preg_match('/R\s*(\d+)\s*C\s*(\d+)/i', $value, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('/^(\d)(\d)$/', trim($value), $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [null, null];
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
}
