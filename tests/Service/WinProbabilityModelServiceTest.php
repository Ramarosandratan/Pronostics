<?php

namespace App\Tests\Service;

use App\Entity\Horse;
use App\Entity\Participation;
use App\Entity\Race;
use App\Service\WinProbabilityFeatureExtractor;
use App\Service\WinProbabilityModelStore;
use App\Service\WinProbabilityModelService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class WinProbabilityModelServiceTest extends TestCase
{
    public function testTrainFromSamplesCreatesModelAndPredictsBetterHorseHigherProbability(): void
    {
        $modelPath = sys_get_temp_dir() . '/win-probability-model-' . uniqid('', true) . '.json';
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new WinProbabilityModelService(
            $entityManager,
            new WinProbabilityFeatureExtractor(),
            new WinProbabilityModelStore($modelPath)
        );

        $samples = [];
        for ($index = 0; $index < 6; $index++) {
            $samples[] = [
                'features' => [
                    'bias' => 1.0,
                    'saddle' => 1.0,
                    'odds' => 0.8,
                    'performance' => 0.95,
                    'age' => 1.0,
                    'earnings' => 0.9,
                    'distance' => 0.7,
                    'field_size' => 0.5,
                    'autostart' => 1.0,
                ],
                'label' => 1,
            ];
            $samples[] = [
                'features' => [
                    'bias' => 1.0,
                    'saddle' => 0.1,
                    'odds' => 0.05,
                    'performance' => 0.15,
                    'age' => 0.2,
                    'earnings' => 0.15,
                    'distance' => 0.7,
                    'field_size' => 0.5,
                    'autostart' => 1.0,
                ],
                'label' => 0,
            ];
        }

        $result = $service->trainFromSamples($samples);
        self::assertTrue($result['trained']);
        self::assertFileExists($modelPath);

        $race = (new Race())
            ->setHippodrome('VINCENNES')
            ->setMeetingNumber(1)
            ->setRaceNumber(1)
            ->setDistanceMeters(2700)
            ->setAutostart(true);

        $goodParticipation = $this->buildParticipation($race, 1, 1.2, '1', 5, '150000', 1);
        $badParticipation = $this->buildParticipation($race, 10, 20.0, '18', 9, '1200', 10);

        $goodProbability = $service->predictParticipationProbability($goodParticipation, 10);
        $badProbability = $service->predictParticipationProbability($badParticipation, 10);

        self::assertNotNull($goodProbability);
        self::assertNotNull($badProbability);
        self::assertGreaterThan($badProbability, $goodProbability);
        self::assertGreaterThan(0.5, $goodProbability);
        self::assertLessThan(0.5, $badProbability);

        @unlink($modelPath);
    }

    private function buildParticipation(Race $race, int $saddleNumber, float $odds, string $music, int $age, string $earnings, int $finishingPosition): Participation
    {
        $participation = new Participation();
        $participation->setRace($race);
        $participation->setHorse((new Horse())->setName('HORSE-' . $saddleNumber));
        $participation->setSaddleNumber($saddleNumber);
        $participation->setOdds($odds);
        $participation->setMusic($music);
        $participation->setAgeAtRace($age);
        $participation->setCareerEarnings($earnings);
        $participation->setFinishingPosition($finishingPosition);

        return $participation;
    }
}
