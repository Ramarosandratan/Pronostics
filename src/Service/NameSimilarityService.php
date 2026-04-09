<?php

namespace App\Service;

final class NameSimilarityService
{
    /**
     * @param string[] $names
     *
     * @return array<int, array{left: string, right: string, score: float}>
     */
    public function findClosePairs(array $names, float $minimumScore = 0.82, int $maxResults = 30): array
    {
        $pairs = [];
        $count = count($names);

        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $left = trim($names[$i]);
                $right = trim($names[$j]);
                if ($left === '' || $right === '') {
                    continue;
                }

                $score = $this->similarityScore($left, $right);
                if ($score >= $minimumScore) {
                    $pairs[] = [
                        'left' => $left,
                        'right' => $right,
                        'score' => $score,
                    ];
                }
            }
        }

        usort(
            $pairs,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score']
        );

        return array_slice($pairs, 0, $maxResults);
    }

    public function canonicalize(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? $normalized;

        return strtoupper(trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized));
    }

    public function similarityScore(string $left, string $right): float
    {
        $a = $this->canonicalize($left);
        $b = $this->canonicalize($right);
        $score = 0.0;

        if ($a !== '' && $b !== '') {
            if ($a === $b) {
                $score = 1.0;
            } else {
                $distance = levenshtein($a, $b);
                $maxLength = max(strlen($a), strlen($b));
                if ($maxLength > 0) {
                    $score = max(0.0, 1 - ($distance / $maxLength));
                }
            }
        }

        return $score;
    }
}
