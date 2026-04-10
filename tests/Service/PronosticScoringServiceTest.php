<?php

namespace App\Tests\Service;

use App\Entity\Horse;
use App\Entity\Participation;
use App\Entity\Race;
use App\Service\PronosticScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

class PronosticScoringServiceTest extends TestCase
{
    public function testScoreRaceRanksBestHorseFirst(): void
    {
        $race = (new Race())
            ->setHippodrome('VINCENNES')
            ->setMeetingNumber(1)
            ->setRaceNumber(1);

        $best = $this->buildParticipation($race, [
            'horseName' => 'ALPHA',
            'saddleNumber' => 1,
            'finishingPosition' => 1,
            'odds' => 1.0,
            'performanceIndicator' => '1',
            'ageAtRace' => 5,
            'careerEarnings' => '1000',
        ]);
        $worst = $this->buildParticipation($race, [
            'horseName' => 'BETA',
            'saddleNumber' => 2,
            'finishingPosition' => 2,
            'odds' => 10.0,
            'performanceIndicator' => '20',
            'ageAtRace' => 10,
            'careerEarnings' => '5000',
        ]);

        $query = $this->createMock(Query::class);
        $query->expects($this->once())
            ->method('setParameter')
            ->with('race', $race)
            ->willReturnSelf();
        $query->expects($this->once())
            ->method('getResult')
            ->willReturn([$best, $worst]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('createQuery')
            ->with($this->stringContains('FROM App\\Entity\\Participation p'))
            ->willReturn($query);

        $service = new PronosticScoringService($entityManager);
        $rankings = $service->scoreRace($race);

        self::assertCount(2, $rankings);
        self::assertSame('ALPHA', $rankings[0]['horse_name']);
        self::assertSame(1, $rankings[0]['rank']);
        self::assertSame('BETA', $rankings[1]['horse_name']);
        self::assertGreaterThan($rankings[1]['score'], $rankings[0]['score']);
        self::assertSame(100.0, $rankings[0]['sub_scores']['position']);
        self::assertSame(100.0, $rankings[0]['sub_scores']['odds']);
        self::assertSame(100.0, $rankings[0]['sub_scores']['performance']);
        self::assertSame(0.0, $rankings[1]['sub_scores']['odds']);
    }

    public function testScoreRaceUsesSaddleNumberAsTieBreakerWhenScoresMatch(): void
    {
        $race = (new Race())
            ->setHippodrome('ENGHIEN')
            ->setMeetingNumber(2)
            ->setRaceNumber(3);

        $firstToAppear = $this->buildParticipation($race, [
            'horseName' => 'FIRST',
            'saddleNumber' => 7,
        ]);
        $secondToAppear = $this->buildParticipation($race, [
            'horseName' => 'SECOND',
            'saddleNumber' => 3,
        ]);

        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn([$firstToAppear, $secondToAppear]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturn($query);

        $service = new PronosticScoringService($entityManager);
        $rankings = $service->scoreRace($race);

        self::assertSame('SECOND', $rankings[0]['horse_name']);
        self::assertSame(3, $rankings[0]['saddle_number']);
        self::assertSame('FIRST', $rankings[1]['horse_name']);
        self::assertSame(7, $rankings[1]['saddle_number']);
        self::assertSame(0.0, $rankings[0]['score']);
        self::assertSame(0.0, $rankings[1]['score']);
    }

    public function testScoreRaceSupportsAggressiveModeWithCustomProfiles(): void
    {
        $race = (new Race())
            ->setHippodrome('AUTEUIL')
            ->setMeetingNumber(3)
            ->setRaceNumber(6);

        $positionHorse = $this->buildParticipation($race, [
            'horseName' => 'POSITION_FIRST',
            'saddleNumber' => 1,
            'finishingPosition' => 1,
            'odds' => 12.0,
            'performanceIndicator' => '15',
            'ageAtRace' => 8,
            'careerEarnings' => '1200',
        ]);
        $oddsHorse = $this->buildParticipation($race, [
            'horseName' => 'ODDS_FIRST',
            'saddleNumber' => 2,
            'finishingPosition' => 2,
            'odds' => 2.0,
            'performanceIndicator' => '15',
            'ageAtRace' => 8,
            'careerEarnings' => '1200',
        ]);

        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn([$positionHorse, $oddsHorse]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturn($query);

        $profiles = [
            PronosticScoringService::MODE_CONSERVATIVE => [
                'position' => 80,
                'odds' => 5,
                'performance' => 5,
                'earnings' => 5,
                'age' => 5,
            ],
            PronosticScoringService::MODE_AGGRESSIVE => [
                'position' => 10,
                'odds' => 80,
                'performance' => 5,
                'earnings' => 3,
                'age' => 2,
            ],
        ];

        $service = new PronosticScoringService($entityManager, $profiles, PronosticScoringService::MODE_CONSERVATIVE);

        $conservativeRankings = $service->scoreRace($race, PronosticScoringService::MODE_CONSERVATIVE);
        $aggressiveRankings = $service->scoreRace($race, PronosticScoringService::MODE_AGGRESSIVE);

        self::assertSame('POSITION_FIRST', $conservativeRankings[0]['horse_name']);
        self::assertSame('ODDS_FIRST', $aggressiveRankings[0]['horse_name']);
    }

    public function testResolveScoringConfigurationFallsBackToConservativeForUnknownMode(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PronosticScoringService($entityManager, [], PronosticScoringService::MODE_CONSERVATIVE);

        $configuration = $service->resolveScoringConfiguration('unknown-mode');

        self::assertSame(PronosticScoringService::MODE_CONSERVATIVE, $configuration['mode']);
        self::assertSame(45.0, $configuration['weights']['position']);
        self::assertSame(25.0, $configuration['weights']['odds']);
    }

    /**
     * @param array{
     *     horseName: string,
     *     saddleNumber?: ?int,
     *     finishingPosition?: ?int,
     *     odds?: ?float,
     *     performanceIndicator?: ?string,
     *     ageAtRace?: ?int,
     *     careerEarnings?: ?string
     * } $definition
     */
    private function buildParticipation(Race $race, array $definition): Participation
    {
        $participation = new Participation();
        $participation->setRace($race);
        $participation->setHorse((new Horse())->setName($definition['horseName']));
        $participation->setSaddleNumber($definition['saddleNumber'] ?? null);
        $participation->setFinishingPosition($definition['finishingPosition'] ?? null);
        $participation->setOdds($definition['odds'] ?? null);
        $participation->setPerformanceIndicator($definition['performanceIndicator'] ?? null);
        $participation->setAgeAtRace($definition['ageAtRace'] ?? null);
        $participation->setCareerEarnings($definition['careerEarnings'] ?? null);

        return $participation;
    }
}
