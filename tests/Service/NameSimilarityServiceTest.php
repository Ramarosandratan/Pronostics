<?php

namespace App\Tests\Service;

use App\Service\NameSimilarityService;
use PHPUnit\Framework\TestCase;

class NameSimilarityServiceTest extends TestCase
{
    public function testCanonicalizeRemovesNoise(): void
    {
        $service = new NameSimilarityService();

        self::assertSame('JEAN DUPONT', $service->canonicalize('  Jean   Dupont  '));
        self::assertSame('KHEOPS D ETE', $service->canonicalize("Kheops d'ete"));
    }

    public function testFindClosePairsReturnsExpectedSuggestion(): void
    {
        $service = new NameSimilarityService();

        $pairs = $service->findClosePairs([
            'KHEOPS',
            'KHEOPS',
            'TITUS',
        ], 0.95, 10);

        self::assertNotEmpty($pairs);
        self::assertSame('KHEOPS', $pairs[0]['left']);
        self::assertSame('KHEOPS', $pairs[0]['right']);
    }
}
