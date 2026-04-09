<?php

namespace App\Controller;

use App\Entity\Race;
use App\Service\PronosticScoringService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PronosticController extends AbstractController
{
    #[Route('/pronostic/{id}', name: 'app_pronostic_show', methods: ['GET'])]
    public function show(Race $race, PronosticScoringService $scoringService): JsonResponse
    {
        $rankings = $scoringService->scoreRace($race);

        return new JsonResponse([
            'race' => [
                'id' => $race->getId(),
                'race_date' => $race->getRaceDate()?->format('Y-m-d'),
                'hippodrome' => $race->getHippodrome(),
                'meeting_number' => $race->getMeetingNumber(),
                'race_number' => $race->getRaceNumber(),
                'discipline' => $race->getDiscipline(),
            ],
            'top' => array_slice($rankings, 0, 5),
            'rankings' => $rankings,
            'count' => count($rankings),
        ]);
    }
}
