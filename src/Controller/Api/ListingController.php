<?php

namespace App\Controller\Api;

use App\Entity\Race;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ListingController extends AbstractController
{
    #[Route('/api/races/recent', name: 'api_races_recent', methods: ['GET'])]
    public function recentRaces(EntityManagerInterface $entityManager): JsonResponse
    {
        // On récupère les 50 dernières courses importées (par ID décroissant ou par date décroissante)
        $races = $entityManager->getRepository(Race::class)->findBy(
            [], 
            ['raceDate' => 'DESC', 'hippodromeName' => 'ASC', 'meetingNumber' => 'ASC', 'raceNumber' => 'ASC'], 
            50
        );

        $results = [];
        foreach ($races as $race) {
            $results[] = [
                'id' => $race->getId(),
                'race_date' => $race->getRaceDate()?->format('Y-m-d'),
                'hippodrome' => $race->getHippodrome()?->getName() ?? $race->getHippodromeName(),
                'meeting_number' => $race->getMeetingNumber(),
                'race_number' => $race->getRaceNumber(),
                'discipline' => $race->getDiscipline(),
                'distance' => $race->getDistanceMeters(),
                'allocation' => $race->getAllocation(),
                'time' => $race->getRaceTime(),
            ];
        }

        return new JsonResponse($results);
    }
}
