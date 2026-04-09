<?php

namespace App\Tests\Controller;

use App\Controller\PronosticController;
use App\Entity\Race;
use App\Service\PronosticScoringService;
use PHPUnit\Framework\TestCase;

class PronosticControllerTest extends TestCase
{
    public function testShowReturnsTopFiveAndRaceMetadata(): void
    {
        $race = (new Race())
            ->setRaceDate(new \DateTimeImmutable('2026-04-09'))
            ->setHippodrome('VINCENNES')
            ->setMeetingNumber(4)
            ->setRaceNumber(2)
            ->setDiscipline('TROT');

        $rankings = [];
        for ($index = 1; $index <= 6; $index++) {
            $rankings[] = [
                'participation_id' => $index,
                'horse_id' => $index,
                'horse_name' => 'Horse ' . $index,
                'saddle_number' => $index,
                'score' => 100 - $index,
                'sub_scores' => [
                    'position' => 90.0,
                    'odds' => 80.0,
                    'performance' => 70.0,
                    'earnings' => 60.0,
                    'age' => 50.0,
                ],
                'rank' => $index,
            ];
        }

        $scoringService = $this->createMock(PronosticScoringService::class);
        $scoringService->expects($this->once())
            ->method('scoreRace')
            ->with($race)
            ->willReturn($rankings);

        $controller = new PronosticController();
        $response = $controller->show($race, $scoringService);

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(4, $payload['race']['meeting_number']);
        self::assertSame(2, $payload['race']['race_number']);
        self::assertSame('VINCENNES', $payload['race']['hippodrome']);
        self::assertSame('2026-04-09', $payload['race']['race_date']);
        self::assertCount(5, $payload['top']);
        self::assertCount(6, $payload['rankings']);
        self::assertSame(6, $payload['count']);
        self::assertSame('Horse 1', $payload['top'][0]['horse_name']);
        self::assertSame('Horse 5', $payload['top'][4]['horse_name']);
    }
}
