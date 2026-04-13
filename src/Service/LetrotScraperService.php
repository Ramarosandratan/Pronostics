<?php

namespace App\Service;

use App\Exception\ImportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LetrotScraperService
{
    private const LETROT_BASE_URL = 'https://www.letrot.com';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return list<string>
     */
    public function discoverProgrammeUrlsForDate(string $date): array
    {
        $normalizedDate = trim($date);
        if ($normalizedDate === '') {
            throw new ImportException('Date Letrot vide pour la decouverte automatique.');
        }

        $html = $this->fetchHtml(sprintf(self::LETROT_BASE_URL.'/courses/%s', $normalizedDate));

        return $this->extractProgrammeUrlsFromTodayHtml($html, $normalizedDate);
    }

    /**
     * @return list<array{payload: array<string, mixed>, race_url: string, pdf_url: ?string, programme_url: string}>
     */
    public function scrapeProgrammeAllRaces(string $programmeUrl): array
    {
        $programmeData = $this->getProgrammeRaceUrls($programmeUrl);

        $results = [];
        foreach ($programmeData['race_urls'] as $raceUrl) {
            $raceHtml = $this->fetchHtml($raceUrl);
            $results[] = [
                'payload' => $this->extractPayloadFromRaceHtml($raceHtml),
                'race_url' => $raceUrl,
                'pdf_url' => $programmeData['pdf_url'],
                'programme_url' => $programmeData['programme_url'],
            ];
        }

        return $results;
    }

    /**
     * @return array{programme_url: string, pdf_url: ?string, race_urls: list<string>}
     */
    public function getProgrammeRaceUrls(string $programmeUrl): array
    {
        $normalizedUrl = trim($programmeUrl);
        if (!$this->isProgrammeUrl($normalizedUrl)) {
            throw new ImportException('URL programme Letrot invalide pour le mode automatique.');
        }

        [$dateCourse, $hippodromeId] = $this->parseProgrammeUrl($normalizedUrl);
        $programmeHtml = $this->fetchHtml($normalizedUrl);
        $pdfUrl = $this->extractPdfUrl($programmeHtml);
        $raceUrls = $this->extractRaceUrlsFromProgrammeHtml($programmeHtml, $dateCourse, $hippodromeId);

        if ($raceUrls === []) {
            throw new ImportException(sprintf('Aucune course detectee pour la reunion %s.', $normalizedUrl));
        }

        return [
            'programme_url' => $normalizedUrl,
            'pdf_url' => $pdfUrl,
            'race_urls' => $raceUrls,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, race_url: string, pdf_url: ?string}
     */
    public function scrape(string $url, int $courseNumber = 1): array
    {
        $normalizedUrl = trim($url);
        if ($normalizedUrl === '') {
            throw new ImportException('URL Letrot vide.');
        }

        if ($this->isRaceUrl($normalizedUrl)) {
            $html = $this->fetchHtml($normalizedUrl);
            $payload = $this->extractPayloadFromRaceHtml($html);

            return [
                'payload' => $payload,
                'race_url' => $normalizedUrl,
                'pdf_url' => null,
            ];
        }

        if (!$this->isProgrammeUrl($normalizedUrl)) {
            throw new ImportException('URL Letrot non supportee: utilise une URL de programme ou de course.');
        }

        $programmeHtml = $this->fetchHtml($normalizedUrl);
        $raceUrl = $this->buildRaceUrlFromProgramme($normalizedUrl, $courseNumber);
        $pdfUrl = $this->extractPdfUrl($programmeHtml);

        $raceHtml = $this->fetchHtml($raceUrl);
        $payload = $this->extractPayloadFromRaceHtml($raceHtml);

        return [
            'payload' => $payload,
            'race_url' => $raceUrl,
            'pdf_url' => $pdfUrl,
        ];
    }

    private function fetchHtml(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; PMUPronosticsBot/1.0; +https://localhost)',
            ],
        ]);

        return $response->getContent();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseProgrammeUrl(string $programmeUrl): array
    {
        $matches = [];
        if (preg_match('#^https?://(?:www\.)?letrot\.com/courses/programme/(\d{4}-\d{2}-\d{2})/(\d+)/?$#i', $programmeUrl, $matches) !== 1) {
            throw new ImportException('Impossible d extraire la date/reunion depuis l URL programme.');
        }

        return [$matches[1], $matches[2]];
    }

    private function isProgrammeUrl(string $url): bool
    {
        return preg_match('#^https?://www\.letrot\.com/courses/programme/\d{4}-\d{2}-\d{2}/\d+/?$#i', $url) === 1;
    }

    private function isRaceUrl(string $url): bool
    {
        return preg_match('#^https?://www\.letrot\.com/courses/\d{4}-\d{2}-\d{2}/\d+/\d+/?$#i', $url) === 1;
    }

    private function buildRaceUrlFromProgramme(string $programmeUrl, int $courseNumber): string
    {
        [$dateCourse, $hippodromeId] = $this->parseProgrammeUrl($programmeUrl);

        $safeCourseNumber = max(1, $courseNumber);

        return sprintf(self::LETROT_BASE_URL.'/courses/%s/%s/%d', $dateCourse, $hippodromeId, $safeCourseNumber);
    }

    private function extractPdfUrl(string $programmeHtml): ?string
    {
        $matches = [];
        if (preg_match('#https://pro\.letrot\.com/siteinstit/[^"\s]+\.pdf#i', $programmeHtml, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayloadFromRaceHtml(string $html): array
    {
        $data = $this->decodeRaceDetailPayload($html);
        if (!is_array($data) || !is_array($data['raceResult'] ?? null)) {
            throw new ImportException('Impossible de decoder raceResult Letrot.');
        }

        return $this->mapRaceResultToImportPayload($data['raceResult']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeRaceDetailPayload(string $html): ?array
    {
        $matches = [];
        if (preg_match('/<race-detail[^>]*:payload="([^"]+)"/s', $html, $matches) !== 1) {
            return null;
        }

        $json = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $raceResult
     *
     * @return array<string, mixed>
     */
    private function mapRaceResultToImportPayload(array $raceResult): array
    {
        $participants = [];

        $partants = is_array($raceResult['partants'] ?? null) ? $raceResult['partants'] : [];
        foreach ($partants as $partant) {
            if (!is_array($partant)) {
                continue;
            }

            $participants[] = [
                'horse_name' => $this->stringOrNull($partant['name'] ?? null),
                'sex' => $this->stringOrNull($partant['sexe'] ?? null),
                'age_at_race' => $partant['age'] ?? null,
                'saddle_number' => $partant['leavingNumber'] ?? null,
                'finishing_position' => $partant['rang'] ?? null,
                'jockey' => $this->stringOrNull($partant['driver'] ?? null),
                'trainer' => $this->stringOrNull($partant['coach'] ?? null),
                'owner' => $this->stringOrNull($partant['owner'] ?? null),
                'distance_or_weight' => $partant['distance'] ?? null,
                'shoeing_or_draw' => $this->stringOrNull($partant['ferrure'] ?? null),
                'performance_indicator' => $partant['avisEntraineur'] ?? null,
                'odds' => $partant['rapportProbable'] ?? null,
                'music' => $this->stringOrNull($partant['song'] ?? null),
                'career_earnings' => $partant['earnings'] ?? null,
            ];
        }

        return [
            'race' => [
                'hippodrome' => $this->stringOrNull($raceResult['nomHippodrome'] ?? null),
                'meeting_number' => $raceResult['numReunion'] ?? null,
                'race_number' => $raceResult['numCourse'] ?? null,
                'race_date' => $this->stringOrNull($raceResult['dateCourse'] ?? null),
                'discipline' => $this->stringOrNull($raceResult['discipline'] ?? null),
                'distance_meters' => $raceResult['distance'] ?? null,
                'allocation' => $raceResult['allocation'] ?? null,
                'category' => $this->stringOrNull($raceResult['categorie'] ?? null),
                'race_time' => $this->stringOrNull($raceResult['heureCourse'] ?? null),
                'track_type' => $this->stringOrNull($raceResult['typePiste'] ?? null),
                'track_rope' => $this->stringOrNull($raceResult['corde'] ?? null),
                'autostart' => $raceResult['autostart'] ?? null,
            ],
            'participants' => $participants,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return list<string>
     */
    private function extractProgrammeUrlsFromTodayHtml(string $html, string $date): array
    {
        $urls = [];
        $patterns = [
            '#https?://(?:www\.)?letrot\.com/courses/programme/(\d{4}-\d{2}-\d{2})/(\d+)#i',
            '#/courses/programme/(\d{4}-\d{2}-\d{2})/(\d+)#i',
        ];

        foreach ($patterns as $pattern) {
            $matches = [];
            preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (($match[1] ?? '') !== $date) {
                    continue;
                }

                $urls[] = sprintf(self::LETROT_BASE_URL.'/courses/programme/%s/%s', $match[1], $match[2]);
            }
        }

        $urls = array_values(array_unique($urls));
        sort($urls);

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function extractRaceUrlsFromProgrammeHtml(string $html, string $dateCourse, string $hippodromeId): array
    {
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pattern = sprintf(
            '#(?:\\/|/)courses/%s/%s/(\d+)#i',
            preg_quote($dateCourse, '#'),
            preg_quote($hippodromeId, '#')
        );

        $matches = [];
        preg_match_all($pattern, $decodedHtml, $matches, PREG_SET_ORDER);

        $raceNumbers = [];
        foreach ($matches as $match) {
            if (!isset($match[1]) || !ctype_digit($match[1])) {
                continue;
            }

            $raceNumbers[] = (int) $match[1];
        }

        $raceNumbers = array_values(array_unique($raceNumbers));

        if ($raceNumbers === []) {
            $idPattern = sprintf(
                '#"id":"%s-%s-(\d+)"#i',
                preg_quote($dateCourse, '#'),
                preg_quote($hippodromeId, '#')
            );
            $idMatches = [];
            preg_match_all($idPattern, $decodedHtml, $idMatches, PREG_SET_ORDER);
            foreach ($idMatches as $idMatch) {
                if (!isset($idMatch[1]) || !ctype_digit($idMatch[1])) {
                    continue;
                }

                $raceNumbers[] = (int) $idMatch[1];
            }

            $raceNumbers = array_values(array_unique($raceNumbers));
        }

        sort($raceNumbers);

        $urls = [];
        foreach ($raceNumbers as $raceNumber) {
            $urls[] = sprintf(self::LETROT_BASE_URL.'/courses/%s/%s/%d', $dateCourse, $hippodromeId, $raceNumber);
        }

        return $urls;
    }
}
