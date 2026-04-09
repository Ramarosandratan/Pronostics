<?php

namespace App\Controller;

use App\Entity\Horse;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\Race;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gestion')]
class ManagementController extends AbstractController
{
    private const DB_TABLE_MISSING_ON_WRITE = 'Table absente: lancez les migrations Doctrine avant d\'ajouter des donnees.';

    #[Route('', name: 'app_management_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $schemaReady = true;

        try {
            $counts = [
                'persons' => $entityManager->getRepository(Person::class)->count([]),
                'horses' => $entityManager->getRepository(Horse::class)->count([]),
                'races' => $entityManager->getRepository(Race::class)->count([]),
                'participations' => $entityManager->getRepository(Participation::class)->count([]),
            ];
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $schemaReady = false;
            $counts = [
                'persons' => 0,
                'horses' => 0,
                'races' => 0,
                'participations' => 0,
            ];
            $this->addFlash('error', 'Base non initialisee: executez les migrations Doctrine pour creer les tables.');
        }

        return $this->render('manage/index.html.twig', [
            'counts' => $counts,
            'schemaReady' => $schemaReady,
        ]);
    }

    #[Route('/personnes', name: 'app_management_people', methods: ['GET', 'POST'])]
    public function people(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));

            if ($name === '') {
                $this->addFlash('error', 'Le nom est obligatoire.');

                return $this->redirectToRoute('app_management_people');
            }

            $person = (new Person())->setName($name);

            try {
                $entityManager->persist($person);
                $entityManager->flush();
                $this->addFlash('success', 'Personne ajoutee avec succes.');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Cette personne existe deja.');
            } catch (\Throwable $exception) {
                if (!$this->isMissingTableException($exception)) {
                    throw $exception;
                }

                $this->addFlash('error', self::DB_TABLE_MISSING_ON_WRITE);
            }

            return $this->redirectToRoute('app_management_people');
        }

        $people = [];
        try {
            $people = $entityManager->getRepository(Person::class)->findBy([], ['name' => 'ASC']);
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table absente: lancez les migrations Doctrine pour afficher les personnes.');
        }

        return $this->render('manage/people.html.twig', [
            'people' => $people,
        ]);
    }

    #[Route('/chevaux', name: 'app_management_horses', methods: ['GET', 'POST'])]
    public function horses(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            $sex = strtoupper(trim((string) $request->request->get('sex', '')));
            $sex = $sex !== '' ? $sex : null;

            if ($name === '') {
                $this->addFlash('error', 'Le nom du cheval est obligatoire.');

                return $this->redirectToRoute('app_management_horses');
            }

            $horse = (new Horse())
                ->setName($name)
                ->setSex($sex);

            try {
                $entityManager->persist($horse);
                $entityManager->flush();
                $this->addFlash('success', 'Cheval ajoute avec succes.');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Ce cheval existe deja.');
            } catch (\Throwable $exception) {
                if (!$this->isMissingTableException($exception)) {
                    throw $exception;
                }

                $this->addFlash('error', self::DB_TABLE_MISSING_ON_WRITE);
            }

            return $this->redirectToRoute('app_management_horses');
        }

        $horses = [];
        try {
            $horses = $entityManager->getRepository(Horse::class)->findBy([], ['name' => 'ASC']);
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table absente: lancez les migrations Doctrine pour afficher les chevaux.');
        }

        return $this->render('manage/horses.html.twig', [
            'horses' => $horses,
        ]);
    }

    #[Route('/courses', name: 'app_management_races', methods: ['GET', 'POST'])]
    public function races(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $this->handleRacePost($request, $entityManager);
            return $this->redirectToRoute('app_management_races');
        }

        return $this->render('manage/races.html.twig', [
            'races' => $this->loadRaces($entityManager),
        ]);
    }

    #[Route('/participations', name: 'app_management_participations', methods: ['GET'])]
    public function participations(EntityManagerInterface $entityManager): Response
    {
        $participations = [];
        try {
            $participations = $entityManager->createQuery(
                'SELECT p, r, h, j, t, o
                FROM App\\Entity\\Participation p
                JOIN p.race r
                JOIN p.horse h
                LEFT JOIN p.jockey j
                LEFT JOIN p.trainer t
                LEFT JOIN p.owner o
                ORDER BY p.id DESC'
            )->setMaxResults(150)->getResult();
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Tables absentes: lancez les migrations Doctrine pour afficher les participations.');
        }

        return $this->render('manage/participations.html.twig', [
            'participations' => $participations,
        ]);
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
     * @return array{0: ?Race, 1: string[]}
     */
    private function buildRaceFromRequest(Request $request): array
    {
        $errors = [];
        $raceDateInput = trim((string) $request->request->get('race_date', ''));
        $hippodrome = trim((string) $request->request->get('hippodrome', ''));
        $meetingNumber = (int) $request->request->get('meeting_number', 0);
        $raceNumber = (int) $request->request->get('race_number', 0);
        $discipline = trim((string) $request->request->get('discipline', ''));
        $sourceDateCode = trim((string) $request->request->get('source_date_code', ''));

        if ($hippodrome === '' || $meetingNumber <= 0 || $raceNumber <= 0) {
            $errors[] = 'Hippodrome, reunion et numero de course sont obligatoires.';
        }

        $raceDate = $this->parseDate($raceDateInput);
        if ($raceDateInput !== '' && $raceDate === null) {
            $errors[] = 'Format de date invalide (attendu: YYYY-MM-DD).';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $race = (new Race())
            ->setRaceDate($raceDate)
            ->setHippodrome($hippodrome)
            ->setMeetingNumber($meetingNumber)
            ->setRaceNumber($raceNumber)
            ->setDiscipline($discipline !== '' ? $discipline : null)
            ->setSourceDateCode($sourceDateCode !== '' ? $sourceDateCode : null);

        return [$race, []];
    }

    private function handleRacePost(Request $request, EntityManagerInterface $entityManager): void
    {
        [$race, $errors] = $this->buildRaceFromRequest($request);

        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        if ($race === null) {
            return;
        }

        try {
            $entityManager->persist($race);
            $entityManager->flush();
            $this->addFlash('success', 'Course ajoutee avec succes.');
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'Cette course existe deja.');
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', self::DB_TABLE_MISSING_ON_WRITE);
        }
    }

    /**
     * @return Race[]
     */
    private function loadRaces(EntityManagerInterface $entityManager): array
    {
        try {
            return $entityManager->getRepository(Race::class)->findBy([], ['id' => 'DESC']);
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table absente: lancez les migrations Doctrine pour afficher les courses.');

            return [];
        }
    }

    private function isMissingTableException(\Throwable $exception): bool
    {
        if ($exception instanceof TableNotFoundException) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'relation')
            && str_contains(strtolower($exception->getMessage()), 'does not exist');
    }
}
