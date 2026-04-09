<?php

namespace App\Controller;

use App\Entity\Horse;
use App\Entity\Hippodrome;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\Race;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gestion')]
class ManagementCrudController extends AbstractController
{
    #[Route('/personnes/{id}/modifier', name: 'app_management_person_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updatePerson(Person $person, Request $request, EntityManagerInterface $entityManager): Response
    {
        $redirectParams = $this->readListContext($request, 25);
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'Le nom est obligatoire.');

            return $this->redirectToRoute('app_management_people', $redirectParams + ['edit' => $person->getId()]);
        }

        try {
            $person->setName($name);
            $entityManager->flush();
            $this->addFlash('success', 'Personne modifiee avec succes.');
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'Cette personne existe deja.');

            return $this->redirectToRoute('app_management_people', $redirectParams + ['edit' => $person->getId()]);
        }

        return $this->redirectToRoute('app_management_people', $redirectParams);
    }

    #[Route('/personnes/{id}/supprimer', name: 'app_management_person_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deletePerson(Person $person, EntityManagerInterface $entityManager): Response
    {
        try {
            $entityManager->remove($person);
            $entityManager->flush();
            $this->addFlash('success', 'Personne supprimee avec succes.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Suppression impossible: personne referencee par des participations.');
        }

        return $this->redirectToRoute('app_management_people');
    }

    #[Route('/chevaux/{id}/modifier', name: 'app_management_horse_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updateHorse(Horse $horse, Request $request, EntityManagerInterface $entityManager): Response
    {
        $name = trim((string) $request->request->get('name', ''));
        $sex = strtoupper(trim((string) $request->request->get('sex', '')));
        $sex = $sex !== '' ? $sex : null;

        $redirectParams = $this->readListContext($request, 25);

        if ($name === '') {
            $this->addFlash('error', 'Le nom est obligatoire.');

            return $this->redirectToRoute('app_management_horses', $redirectParams + ['edit' => $horse->getId()]);
        }

        try {
            $horse->setName($name)->setSex($sex);
            $entityManager->flush();
            $this->addFlash('success', 'Cheval modifie avec succes.');
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'Ce cheval existe deja.');

            return $this->redirectToRoute('app_management_horses', $redirectParams + ['edit' => $horse->getId()]);
        }

        return $this->redirectToRoute('app_management_horses', $redirectParams);
    }

    #[Route('/chevaux/{id}/supprimer', name: 'app_management_horse_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteHorse(Horse $horse, EntityManagerInterface $entityManager): Response
    {
        try {
            $entityManager->remove($horse);
            $entityManager->flush();
            $this->addFlash('success', 'Cheval supprime avec succes.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Suppression impossible: cheval reference par des participations.');
        }

        return $this->redirectToRoute('app_management_horses');
    }

    #[Route('/courses/{id}/modifier', name: 'app_management_race_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updateRace(Race $race, Request $request, EntityManagerInterface $entityManager): Response
    {
        $redirectParams = $this->readListContext($request, 25);
        $keepEditMode = false;
        $raceDate = $this->parseDate(trim((string) $request->request->get('race_date', '')));
        $hippodromeId = (int) $request->request->get('hippodrome_id', 0);
        $meetingNumber = (int) $request->request->get('meeting_number', 0);
        $raceNumber = (int) $request->request->get('race_number', 0);
        $discipline = trim((string) $request->request->get('discipline', ''));
        $sourceDateCode = trim((string) $request->request->get('source_date_code', ''));

        if ($hippodromeId <= 0 || $meetingNumber <= 0 || $raceNumber <= 0) {
            $this->addFlash('error', 'Hippodrome, reunion et numero de course sont obligatoires.');
            $keepEditMode = true;
        }

        $hippodrome = null;
        if (!$keepEditMode) {
            $hippodrome = $entityManager->getRepository(Hippodrome::class)->find($hippodromeId);
            if (!$hippodrome instanceof Hippodrome) {
                $this->addFlash('error', 'Hippodrome non trouve.');
                $keepEditMode = true;
            }
        }

        if (!$keepEditMode && $hippodrome instanceof Hippodrome) {
            try {
                $race
                    ->setRaceDate($raceDate)
                    ->setHippodrome($hippodrome)
                    ->setMeetingNumber($meetingNumber)
                    ->setRaceNumber($raceNumber)
                    ->setDiscipline($discipline !== '' ? $discipline : null)
                    ->setSourceDateCode($sourceDateCode !== '' ? $sourceDateCode : null);

                $entityManager->flush();
                $this->addFlash('success', 'Course modifiee avec succes.');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Cette course existe deja.');
                $keepEditMode = true;
            }
        }

        if ($keepEditMode) {
            $redirectParams['edit'] = $race->getId();
        }

        return $this->redirectToRoute('app_management_races', $redirectParams);
    }

    #[Route('/courses/{id}/supprimer', name: 'app_management_race_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteRace(Race $race, EntityManagerInterface $entityManager): Response
    {
        try {
            $entityManager->remove($race);
            $entityManager->flush();
            $this->addFlash('success', 'Course supprimee avec succes.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Suppression impossible pour cette course.');
        }

        return $this->redirectToRoute('app_management_races');
    }

    #[Route('/participations/creer', name: 'app_management_participation_create', methods: ['POST'])]
    public function createParticipation(Request $request, EntityManagerInterface $entityManager): Response
    {
        $participation = new Participation();
        $error = $this->hydrateParticipation($participation, $request, $entityManager);

        if ($error !== null) {
            $this->addFlash('error', $error);
        } else {
            try {
                $entityManager->persist($participation);
                $entityManager->flush();
                $this->addFlash('success', 'Participation ajoutee avec succes.');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Participation deja existante pour cette course et ce cheval.');
            }
        }

        return $this->redirectToRoute('app_management_participations');
    }

    #[Route('/participations/{id}/modifier', name: 'app_management_participation_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updateParticipation(Participation $participation, Request $request, EntityManagerInterface $entityManager): Response
    {
        $redirectParams = $this->readListContext($request, 20);
        $error = $this->hydrateParticipation($participation, $request, $entityManager);

        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_management_participations', $redirectParams + ['edit' => $participation->getId()]);
        } else {
            try {
                $entityManager->flush();
                $this->addFlash('success', 'Participation modifiee avec succes.');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Participation deja existante pour cette course et ce cheval.');

                return $this->redirectToRoute('app_management_participations', $redirectParams + ['edit' => $participation->getId()]);
            }
        }

        return $this->redirectToRoute('app_management_participations', $redirectParams);
    }

    #[Route('/participations/{id}/supprimer', name: 'app_management_participation_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteParticipation(Participation $participation, EntityManagerInterface $entityManager): Response
    {
        try {
            $entityManager->remove($participation);
            $entityManager->flush();
            $this->addFlash('success', 'Participation supprimee avec succes.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Suppression impossible pour cette participation.');
        }

        return $this->redirectToRoute('app_management_participations');
    }

    private function parseDate(string $date): ?\DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $parsed ?: null;
    }

    /**
     * @return array{page: int, per_page: int}
     */
    private function readListContext(Request $request, int $defaultPerPage): array
    {
        $page = max(1, (int) $request->request->get('page', 1));
        $perPage = (int) $request->request->get('per_page', $defaultPerPage);
        if (!in_array($perPage, [10, 25, 50], true)) {
            $perPage = $defaultPerPage;
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function hydrateParticipation(Participation $participation, Request $request, EntityManagerInterface $entityManager): ?string
    {
        $raceId = (int) $request->request->get('race_id', 0);
        $horseId = (int) $request->request->get('horse_id', 0);
        $jockeyId = (int) $request->request->get('jockey_id', 0);
        $trainerId = (int) $request->request->get('trainer_id', 0);
        $ownerId = (int) $request->request->get('owner_id', 0);

        $race = $entityManager->getRepository(Race::class)->find($raceId);
        $horse = $entityManager->getRepository(Horse::class)->find($horseId);
        if (!$race instanceof Race || !$horse instanceof Horse) {
            return 'Course et cheval sont obligatoires.';
        }

        $jockey = $jockeyId > 0 ? $entityManager->getRepository(Person::class)->find($jockeyId) : null;
        $trainer = $trainerId > 0 ? $entityManager->getRepository(Person::class)->find($trainerId) : null;
        $owner = $ownerId > 0 ? $entityManager->getRepository(Person::class)->find($ownerId) : null;

        $participation
            ->setRace($race)
            ->setHorse($horse)
            ->setJockey($jockey instanceof Person ? $jockey : null)
            ->setTrainer($trainer instanceof Person ? $trainer : null)
            ->setOwner($owner instanceof Person ? $owner : null)
            ->setSaddleNumber($this->toIntNullable((string) $request->request->get('saddle_number', '')))
            ->setFinishingPosition($this->toIntNullable((string) $request->request->get('finishing_position', '')))
            ->setAgeAtRace($this->toIntNullable((string) $request->request->get('age_at_race', '')))
            ->setDistanceOrWeight($this->toFloatNullable((string) $request->request->get('distance_or_weight', '')))
            ->setShoeingOrDraw($this->toStringNullable((string) $request->request->get('shoeing_or_draw', '')))
            ->setPerformanceIndicator($this->toStringNullable((string) $request->request->get('performance_indicator', '')))
            ->setOdds($this->toFloatNullable((string) $request->request->get('odds', '')))
            ->setMusic($this->toStringNullable((string) $request->request->get('music', '')))
            ->setCareerEarnings($this->toBigIntNullable((string) $request->request->get('career_earnings', '')));

        return null;
    }

    private function toIntNullable(string $value): ?int
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : (int) $trimmed;
    }

    private function toFloatNullable(string $value): ?float
    {
        $trimmed = trim(str_replace(',', '.', $value));

        return $trimmed === '' ? null : (float) $trimmed;
    }

    private function toStringNullable(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function toBigIntNullable(string $value): ?string
    {
        $clean = preg_replace('/[^0-9-]/', '', $value);
        if ($clean === null || $clean === '' || $clean === '-') {
            return null;
        }

        return $clean;
    }
}
