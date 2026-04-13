<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

final class DataQualityService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly float $minConfidence = 0.75,
    ) {
    }

    /**
     * @return array{
        *   summary: array{global_confidence: float, min_confidence: float, scraped_races: int, scraped_participations: int, last_imported_at: ?string, races_without_import_state: int},
     *   participation_metrics: list<array{field: string, label: string, filled: int, total: int, rate: float}>,
     *   race_metrics: list<array{field: string, label: string, filled: int, total: int, rate: float}>,
     *   alerts: list<string>
     * }
     */
    public function buildReport(): array
    {
        $connection = $this->entityManager->getConnection();

        $scrapedParticipations = $connection->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN p.odds IS NOT NULL THEN 1 ELSE 0 END) AS odds_filled,
                SUM(CASE WHEN p.finishing_position IS NOT NULL THEN 1 ELSE 0 END) AS finishing_position_filled,
                SUM(CASE WHEN p.performance_indicator IS NOT NULL AND TRIM(p.performance_indicator) <> '' THEN 1 ELSE 0 END) AS performance_indicator_filled,
                SUM(CASE WHEN p.career_earnings IS NOT NULL THEN 1 ELSE 0 END) AS career_earnings_filled,
                SUM(CASE WHEN p.age_at_race IS NOT NULL THEN 1 ELSE 0 END) AS age_at_race_filled
            FROM participation p
            INNER JOIN race r ON r.id = p.race_id
            INNER JOIN scrape_import_state s
                ON s.race_date = r.race_date
                AND s.meeting_number = r.meeting_number
                AND s.race_number = r.race_number"
        ) ?: [];

        $scrapedRaces = $connection->fetchAssociative(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN r.race_date IS NOT NULL THEN 1 ELSE 0 END) AS race_date_filled,
                SUM(CASE WHEN r.discipline IS NOT NULL AND TRIM(r.discipline) <> '' THEN 1 ELSE 0 END) AS discipline_filled,
                SUM(CASE WHEN r.distance_meters IS NOT NULL THEN 1 ELSE 0 END) AS distance_meters_filled,
                SUM(CASE WHEN r.allocation IS NOT NULL THEN 1 ELSE 0 END) AS allocation_filled,
                SUM(CASE WHEN r.race_category IS NOT NULL AND TRIM(r.race_category) <> '' THEN 1 ELSE 0 END) AS race_category_filled,
                SUM(CASE WHEN r.race_time IS NOT NULL AND TRIM(r.race_time) <> '' THEN 1 ELSE 0 END) AS race_time_filled,
                SUM(CASE WHEN r.track_type IS NOT NULL AND TRIM(r.track_type) <> '' THEN 1 ELSE 0 END) AS track_type_filled,
                SUM(CASE WHEN r.track_rope IS NOT NULL AND TRIM(r.track_rope) <> '' THEN 1 ELSE 0 END) AS track_rope_filled,
                SUM(CASE WHEN r.autostart IS NOT NULL THEN 1 ELSE 0 END) AS autostart_filled
            FROM race r
            INNER JOIN scrape_import_state s
                ON s.race_date = r.race_date
                AND s.meeting_number = r.meeting_number
                AND s.race_number = r.race_number"
        ) ?: [];

        $racesWithoutImportState = (int) $connection->fetchOne(
            'SELECT COUNT(*)
            FROM race r
            LEFT JOIN scrape_import_state s
                ON s.race_date = r.race_date
                AND s.meeting_number = r.meeting_number
                AND s.race_number = r.race_number
            WHERE s.id IS NULL'
        );

        $lastImportedAt = $connection->fetchOne('SELECT MAX(last_imported_at) FROM scrape_import_state');

        $participationTotal = (int) ($scrapedParticipations['total'] ?? 0);
        $raceTotal = (int) ($scrapedRaces['total'] ?? 0);

        $participationMetrics = [
            $this->metric('odds', 'Cote probable', (int) ($scrapedParticipations['odds_filled'] ?? 0), $participationTotal),
            $this->metric('finishing_position', 'Position arrivee', (int) ($scrapedParticipations['finishing_position_filled'] ?? 0), $participationTotal),
            $this->metric('performance_indicator', 'Indicateur performance', (int) ($scrapedParticipations['performance_indicator_filled'] ?? 0), $participationTotal),
            $this->metric('career_earnings', 'Gains carriere', (int) ($scrapedParticipations['career_earnings_filled'] ?? 0), $participationTotal),
            $this->metric('age_at_race', 'Age a la course', (int) ($scrapedParticipations['age_at_race_filled'] ?? 0), $participationTotal),
        ];

        $raceMetrics = [
            $this->metric('race_date', 'Date course', (int) ($scrapedRaces['race_date_filled'] ?? 0), $raceTotal),
            $this->metric('discipline', 'Discipline', (int) ($scrapedRaces['discipline_filled'] ?? 0), $raceTotal),
            $this->metric('distance_meters', 'Distance (m)', (int) ($scrapedRaces['distance_meters_filled'] ?? 0), $raceTotal),
            $this->metric('allocation', 'Allocation', (int) ($scrapedRaces['allocation_filled'] ?? 0), $raceTotal),
            $this->metric('race_category', 'Categorie', (int) ($scrapedRaces['race_category_filled'] ?? 0), $raceTotal),
            $this->metric('race_time', 'Heure course', (int) ($scrapedRaces['race_time_filled'] ?? 0), $raceTotal),
            $this->metric('track_type', 'Type piste', (int) ($scrapedRaces['track_type_filled'] ?? 0), $raceTotal),
            $this->metric('track_rope', 'Corde', (int) ($scrapedRaces['track_rope_filled'] ?? 0), $raceTotal),
            $this->metric('autostart', 'Autostart', (int) ($scrapedRaces['autostart_filled'] ?? 0), $raceTotal),
        ];

        $globalConfidence = $this->computeGlobalConfidence($participationMetrics, $raceMetrics);
        $alerts = $this->buildAlerts($globalConfidence, $participationMetrics, $raceMetrics);

        return [
            'summary' => [
                'global_confidence' => $globalConfidence,
                'min_confidence' => $this->minConfidence,
                'scraped_races' => $raceTotal,
                'scraped_participations' => $participationTotal,
                'last_imported_at' => is_string($lastImportedAt) ? $lastImportedAt : null,
                'races_without_import_state' => $racesWithoutImportState,
            ],
            'participation_metrics' => $participationMetrics,
            'race_metrics' => $raceMetrics,
            'alerts' => $alerts,
        ];
    }

    /**
     * @param list<array{field: string, label: string, filled: int, total: int, rate: float}> $participationMetrics
     * @param list<array{field: string, label: string, filled: int, total: int, rate: float}> $raceMetrics
     */
    private function computeGlobalConfidence(array $participationMetrics, array $raceMetrics): float
    {
        $weights = [
            'odds' => 0.2,
            'finishing_position' => 0.2,
            'performance_indicator' => 0.15,
            'career_earnings' => 0.1,
            'age_at_race' => 0.1,
            'discipline' => 0.05,
            'distance_meters' => 0.05,
            'allocation' => 0.05,
            'race_category' => 0.03,
            'race_time' => 0.025,
            'track_type' => 0.025,
            'track_rope' => 0.025,
            'autostart' => 0.025,
        ];

        $rates = [];
        foreach (array_merge($participationMetrics, $raceMetrics) as $metric) {
            $rates[$metric['field']] = $metric['rate'];
        }

        $score = 0.0;
        foreach ($weights as $field => $weight) {
            $score += ($rates[$field] ?? 0.0) * $weight;
        }

        return round($score, 4);
    }

    /**
     * @param list<array{field: string, label: string, filled: int, total: int, rate: float}> $participationMetrics
     * @param list<array{field: string, label: string, filled: int, total: int, rate: float}> $raceMetrics
     *
     * @return list<string>
     */
    private function buildAlerts(float $globalConfidence, array $participationMetrics, array $raceMetrics): array
    {
        $alerts = [];

        if ($globalConfidence < $this->minConfidence) {
            $alerts[] = sprintf('Confiance globale faible: %.1f%% (seuil %.1f%%).', $globalConfidence * 100, $this->minConfidence * 100);
        }

        $allMetrics = array_merge($participationMetrics, $raceMetrics);
        foreach ($allMetrics as $metric) {
            if ($metric['total'] > 0 && $metric['rate'] < 0.6) {
                $alerts[] = sprintf('Champ faible: %s a %.1f%% de completion.', $metric['label'], $metric['rate'] * 100);
            }
        }

        if ($allMetrics === [] || ($participationMetrics[0]['total'] ?? 0) === 0) {
            $alerts[] = 'Aucune course scrapee detectee pour evaluer la qualite.';
        }

        $missingImportState = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*)
            FROM race r
            LEFT JOIN scrape_import_state s
                ON s.race_date = r.race_date
                AND s.meeting_number = r.meeting_number
                AND s.race_number = r.race_number
            WHERE s.id IS NULL'
        );
        if ($missingImportState > 0) {
            $alerts[] = sprintf('Courses sans etat d import: %d.', $missingImportState);
        }

        return $alerts;
    }

    /**
     * @return array{field: string, label: string, filled: int, total: int, rate: float}
     */
    private function metric(string $field, string $label, int $filled, int $total): array
    {
        $rate = $total > 0 ? ($filled / $total) : 0.0;

        return [
            'field' => $field,
            'label' => $label,
            'filled' => $filled,
            'total' => $total,
            'rate' => round($rate, 4),
        ];
    }
}
