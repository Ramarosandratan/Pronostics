<?php

namespace App\Tests\Service;

use App\Service\PronosticCsvExportService;
use PHPUnit\Framework\TestCase;

class PronosticCsvExportServiceTest extends TestCase
{
    public function testRaceRowsContainModeAndWeightsColumns(): void
    {
        $service = new PronosticCsvExportService();
        $rankings = [[
            'rank' => 1,
            'horse_name' => 'ALPHA',
            'saddle_number' => 4,
            'score' => 88.5,
            'sub_scores' => [
                'position' => 92.0,
                'odds' => 86.0,
                'performance' => 81.0,
                'earnings' => 79.0,
                'age' => 90.0,
            ],
        ]];

        $rows = iterator_to_array($service->raceRows($rankings, 'aggressive', [
            'position' => 30.0,
            'odds' => 35.0,
            'performance' => 20.0,
            'earnings' => 10.0,
            'age' => 5.0,
        ]));

        self::assertCount(1, $rows);
        self::assertSame('ALPHA', $rows[0][1]);
        self::assertSame('aggressive', $rows[0][9]);
        self::assertSame('30.0000', $rows[0][10]);
        self::assertSame('35.0000', $rows[0][11]);
    }

    public function testCreateCsvResponseSetsDownloadHeadersAndProducesContent(): void
    {
        $service = new PronosticCsvExportService();
        $response = $service->createCsvResponse('sample.csv', ['col1', 'col2'], [['a', 'b']]);

        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="sample.csv"', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        self::assertStringContainsString("col1;col2", $content);
        self::assertStringContainsString("a;b", $content);
    }
}
