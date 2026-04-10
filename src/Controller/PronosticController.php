<?php

namespace App\Controller;

use App\Entity\Race;
use App\Service\PronosticComparisonService;
use App\Service\PronosticScoringService;
use App\Service\PronosticSnapshotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PronosticController extends AbstractController
{
    #[Route('/pronostic/{id}', name: 'app_pronostic_show', methods: ['GET'])]
    public function show(
        Request $request,
        Race $race,
        PronosticSnapshotService $snapshotService,
        PronosticComparisonService $comparisonService,
        PronosticScoringService $scoringService,
    ): JsonResponse
    {
        $configuration = $scoringService->resolveScoringConfiguration($request->query->get('mode'));
        $rankings = $snapshotService->capturePreRaceSnapshot($race, $configuration['mode']);
        $comparisonService->compareRace($race);

        return new JsonResponse([
            'race' => [
                'id' => $race->getId(),
                'race_date' => $race->getRaceDate()?->format('Y-m-d'),
                'hippodrome' => $race->getHippodrome()?->getName() ?? $race->getHippodromeName(),
                'meeting_number' => $race->getMeetingNumber(),
                'race_number' => $race->getRaceNumber(),
                'discipline' => $race->getDiscipline(),
            ],
            'top' => array_slice($rankings, 0, 5),
            'rankings' => $rankings,
            'count' => count($rankings),
            'scoring' => [
                'mode' => $configuration['mode'],
                'weights' => $configuration['weights'],
            ],
        ]);
    }
}
