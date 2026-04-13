<?php

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RaceWebScraperService
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array{
     *   race?: array<string, string>,
     *   participants?: array<string, string>
     * } $selectors
     *
     * @return array{
     *   url: string,
     *   race: array<string, ?string>,
     *   participants: array<int, array<string, ?string>>
     * }
     */
    public function scrape(string $url, array $selectors): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; PMUPronosticsBot/1.0; +https://localhost)',
            ],
        ]);

        $content = $response->getContent();
        $crawler = new Crawler($content, $url);

        $raceData = $this->extractFields($crawler, $selectors['race'] ?? []);

        $participantSelectors = $selectors['participants'] ?? [];
        $rowSelector = (string) ($participantSelectors['row'] ?? '');
        unset($participantSelectors['row']);

        $participants = [];
        if ($rowSelector !== '') {
            foreach ($crawler->filter($rowSelector) as $node) {
                $participants[] = $this->extractFields(new Crawler($node), $participantSelectors);
            }
        }

        return [
            'url' => $url,
            'race' => $raceData,
            'participants' => $participants,
        ];
    }

    /**
     * @param array<string, string> $fieldSelectors
     *
     * @return array<string, ?string>
     */
    private function extractFields(Crawler $crawler, array $fieldSelectors): array
    {
        $result = [];

        foreach ($fieldSelectors as $field => $locator) {
            $result[$field] = $this->extractValue($crawler, $locator);
        }

        return $result;
    }

    private function extractValue(Crawler $crawler, string $locator): ?string
    {
        [$selector, $attribute] = $this->parseLocator($locator);
        $value = null;

        if ($selector !== '') {
            try {
                $node = $crawler->filter($selector)->first();

                if ($node->count() > 0) {
                    $value = $attribute !== null ? $node->attr($attribute) : $node->text();
                }
            } catch (\Throwable) {
                $value = null;
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function parseLocator(string $locator): array
    {
        $raw = trim($locator);
        $selector = $raw;
        $attribute = null;

        if ($raw === '') {
            $selector = '';
        } else {
            $chunks = explode('@', $raw);
            if (count($chunks) >= 2) {
                $candidateAttribute = trim((string) array_pop($chunks));
                $candidateSelector = trim(implode('@', $chunks));

                if ($candidateSelector !== '' && $candidateAttribute !== '') {
                    $selector = $candidateSelector;
                    $attribute = $candidateAttribute;
                }
            }
        }

        return [$selector, $attribute];
    }
}
