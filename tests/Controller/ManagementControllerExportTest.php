<?php

namespace App\Tests\Controller;

use App\Controller\ManagementController;
use App\Entity\Race;
use App\Service\PronosticCsvExportService;
use App\Service\PronosticKpiService;
use App\Service\PronosticScoringService;
use App\Service\PronosticSnapshotService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementControllerExportTest extends TestCase
{
    public function testExportRacePronosticBuildsCsvResponseWithSelectedMode(): void
    {
        $request = new Request(['mode' => 'aggressive']);
        $race = (new Race())
            ->setHippodrome('VINCENNES')
            ->setMeetingNumber(1)
            ->setRaceNumber(3)
            ->setRaceDate(new \DateTimeImmutable('2026-04-10'));

        $snapshotService = $this->createMock(PronosticSnapshotService::class);
        $snapshotService->expects($this->once())
            ->method('capturePreRaceSnapshot')
            ->with($race, PronosticScoringService::MODE_AGGRESSIVE)
            ->willReturn([]);

        $scoringService = $this->createMock(PronosticScoringService::class);
        $scoringService->expects($this->once())
            ->method('resolveScoringConfiguration')
            ->with('aggressive')
            ->willReturn([
                'mode' => PronosticScoringService::MODE_AGGRESSIVE,
                'weights' => [
                    'position' => 30.0,
                    'odds' => 35.0,
                    'performance' => 20.0,
                    'earnings' => 10.0,
                    'age' => 5.0,
                ],
            ]);

        $csvExportService = $this->createMock(PronosticCsvExportService::class);
        $csvExportService->expects($this->once())->method('raceHeaders')->willReturn(['rank']);
        $csvExportService->expects($this->once())
            ->method('raceRows')
            ->with([], PronosticScoringService::MODE_AGGRESSIVE, $this->isType('array'))
            ->willReturn([]);

        $expectedResponse = new StreamedResponse();
        $csvExportService->expects($this->once())
            ->method('createCsvResponse')
            ->with(
                $this->stringContains('aggressive.csv'),
                ['rank'],
                []
            )
            ->willReturn($expectedResponse);

        $controller = new ManagementController();
        $response = $controller->exportRacePronostic($request, $race, $snapshotService, $scoringService, $csvExportService);

        self::assertSame($expectedResponse, $response);
    }

    public function testExportDashboardUsesCurrentDateFilters(): void
    {
        $request = new Request([
            'from' => '2026-04-01',
            'to' => '2026-04-10',
        ]);

        $kpiService = $this->createMock(PronosticKpiService::class);
        $kpiService->expects($this->once())
            ->method('buildDashboard')
            ->with(
                $this->callback(static fn ($date): bool => $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === '2026-04-01'),
                $this->callback(static fn ($date): bool => $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === '2026-04-10')
            )
            ->willReturn([
                'summary' => [],
                'recent' => [],
            ]);

        $csvExportService = $this->createMock(PronosticCsvExportService::class);
        $csvExportService->expects($this->once())->method('dashboardHeaders')->willReturn(['race_id']);
        $csvExportService->expects($this->once())->method('dashboardRows')->with([])->willReturn([]);

        $expectedResponse = new StreamedResponse();
        $csvExportService->expects($this->once())
            ->method('createCsvResponse')
            ->with(
                'dashboard-pronostics-20260401-20260410.csv',
                ['race_id'],
                []
            )
            ->willReturn($expectedResponse);

        $controller = new ManagementController();
        $response = $controller->exportDashboard($request, $kpiService, $csvExportService);

        self::assertSame($expectedResponse, $response);
    }
}
