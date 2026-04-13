<?php

namespace App\Controller;

use App\Entity\Horse;
use App\Entity\Hippodrome;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\Race;
use App\Service\PronosticComparisonService;
use App\Service\PronosticCsvExportService;
use App\Service\DataQualityService;
use App\Service\PronosticKpiService;
use App\Service\PronosticScoringService;
use App\Service\PronosticSnapshotService;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gestion')]
class ManagementController extends AbstractController
{
    private const DB_TABLE_MISSING_ON_WRITE = 'Table absente: lancez les migrations Doctrine avant d\'ajouter des donnees.';
    private const ITEMS_PER_PAGE = 25;
    private const PARTICIPATIONS_PER_PAGE = 20;
    /** @var int[] */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

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
        $editPerson = null;
        $perPage = $this->resolvePerPage($request, self::ITEMS_PER_PAGE);
        $pagination = $this->buildPagination(0, 1, $perPage);
        $page = max(1, (int) $request->query->get('page', 1));
        $editId = (int) $request->query->get('edit', 0);
        try {
            $totalItems = $entityManager->getRepository(Person::class)->count([]);
            $pagination = $this->buildPagination($totalItems, $page, $perPage);
            $people = $entityManager->getRepository(Person::class)->findBy(
                [],
                ['name' => 'ASC'],
                $perPage,
                $pagination['offset']
            );

            if ($editId > 0) {
                $editPerson = $entityManager->getRepository(Person::class)->find($editId);
            }
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table absente: lancez les migrations Doctrine pour afficher les personnes.');
        }

        return $this->render('manage/people.html.twig', [
            'people' => $people,
            'editPerson' => $editPerson,
            'pagination' => $pagination,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    #[Route('/chevaux', name: 'app_management_horses', methods: ['GET', 'POST'])]
    public function horses(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $this->handleHorsePost($request, $entityManager);

            return $this->redirectToRoute('app_management_horses');
        }

        $horses = [];
        $editHorse = null;
        $perPage = $this->resolvePerPage($request, self::ITEMS_PER_PAGE);
        $editId = (int) $request->query->get('edit', 0);
        $pagination = $this->buildPagination(0, 1, $perPage);
        $page = max(1, (int) $request->query->get('page', 1));
        try {
            $totalItems = $entityManager->getRepository(Horse::class)->count([]);
            $pagination = $this->buildPagination($totalItems, $page, $perPage);
            $horses = $entityManager->getRepository(Horse::class)->findBy(
                [],
                ['name' => 'ASC'],
                $perPage,
                $pagination['offset']
            );

            if ($editId > 0) {
                $editHorse = $entityManager->getRepository(Horse::class)->find($editId);
            }
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table absente: lancez les migrations Doctrine pour afficher les chevaux.');
        }

        return $this->render('manage/horses.html.twig', [
            'horses' => $horses,
            'editHorse' => $editHorse,
            'pagination' => $pagination,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    private function handleHorsePost(Request $request, EntityManagerInterface $entityManager): void
    {
        $name = trim((string) $request->request->get('name', ''));
        $sex = strtoupper(trim((string) $request->request->get('sex', '')));
        $sex = $sex !== '' ? $sex : null;

        if ($name === '') {
            $this->addFlash('error', 'Le nom du cheval est obligatoire.');

            return;
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
    }

    #[Route('/courses', name: 'app_management_races', methods: ['GET', 'POST'])]
    public function races(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $this->handleRacePost($request, $entityManager);
            return $this->redirectToRoute('app_management_races');
        }

        $hippodromes = [];
        $races = [];
        $editRace = null;
        $perPage = $this->resolvePerPage($request, self::ITEMS_PER_PAGE);
        $pagination = $this->buildPagination(0, 1, $perPage);
        $page = max(1, (int) $request->query->get('page', 1));
        $editId = (int) $request->query->get('edit', 0);
        try {
            $hippodromes = $entityManager->getRepository(Hippodrome::class)->findBy([], ['name' => 'ASC']);
            $totalItems = $entityManager->getRepository(Race::class)->count([]);
            $pagination = $this->buildPagination($totalItems, $page, $perPage);
            $races = $entityManager->getRepository(Race::class)->findBy(
                [],
                ['id' => 'DESC'],
                $perPage,
                $pagination['offset']
            );

            if ($editId > 0) {
                $editRace = $entityManager->getRepository(Race::class)->find($editId);
            }
        } catch (\Throwable) {
            // Table might not exist yet
        }

        return $this->render('manage/races.html.twig', [
            'races' => $races,
            'hippodromes' => $hippodromes,
            'editRace' => $editRace,
            'pagination' => $pagination,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    #[Route('/participations', name: 'app_management_participations', methods: ['GET'])]
    public function participations(Request $request, EntityManagerInterface $entityManager): Response
    {
        $participations = [];
        $races = [];
        $horses = [];
        $people = [];
        $editParticipation = null;
        $perPage = $this->resolvePerPage($request, self::PARTICIPATIONS_PER_PAGE);
        $pagination = $this->buildPagination(0, 1, $perPage);

        $page = max(1, (int) $request->query->get('page', 1));
        $editId = (int) $request->query->get('edit', 0);
        try {
            $totalItems = $entityManager->getRepository(Participation::class)->count([]);
            $pagination = $this->buildPagination($totalItems, $page, $perPage);
            $participations = $entityManager->createQuery(
                'SELECT p, r, h, j, t, o
                FROM App\\Entity\\Participation p
                JOIN p.race r
                JOIN p.horse h
                LEFT JOIN p.jockey j
                LEFT JOIN p.trainer t
                LEFT JOIN p.owner o
                ORDER BY p.id DESC'
            )
                ->setFirstResult($pagination['offset'])
                ->setMaxResults($perPage)
                ->getResult();

            $races = $entityManager->getRepository(Race::class)->findBy([], ['id' => 'DESC'], 300);
            $horses = $entityManager->getRepository(Horse::class)->findBy([], ['name' => 'ASC'], 300);
            $people = $entityManager->getRepository(Person::class)->findBy([], ['name' => 'ASC'], 300);

            if ($editId > 0) {
                $editParticipation = $entityManager->getRepository(Participation::class)->find($editId);
            }
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table absente: lancez les migrations Doctrine pour afficher les participations.');
        }

        return $this->render('manage/participations.html.twig', [
            'participations' => $participations,
            'races' => $races,
            'horses' => $horses,
            'people' => $people,
            'editParticipation' => $editParticipation,
            'pagination' => $pagination,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    private function resolvePerPage(Request $request, int $defaultPerPage): int
    {
        $requested = (int) $request->query->get('per_page', $defaultPerPage);

        if (in_array($requested, self::PER_PAGE_OPTIONS, true)) {
            return $requested;
        }

        return $defaultPerPage;
    }

    #[Route('/courses/{id}/pronostic', name: 'app_management_race_pronostic', methods: ['GET'])]
    public function racePronostic(
        Request $request,
        Race $race,
        PronosticSnapshotService $snapshotService,
        PronosticComparisonService $comparisonService,
        PronosticScoringService $scoringService,
    ): Response
    {
        $configuration = $scoringService->resolveScoringConfiguration($request->query->get('mode'));
        $rankings = $snapshotService->capturePreRaceSnapshot($race, $configuration['mode']);
        $comparisonService->compareRace($race);

        return $this->render('manage/pronostic.html.twig', [
            'race' => $race,
            'topRankings' => array_slice($rankings, 0, 5),
            'rankings' => $rankings,
            'scoringMode' => $configuration['mode'],
            'scoringWeights' => $configuration['weights'],
        ]);
    }

    #[Route('/courses/{id}/pronostic/export', name: 'app_management_race_pronostic_export', methods: ['GET'])]
    public function exportRacePronostic(
        Request $request,
        Race $race,
        PronosticSnapshotService $snapshotService,
        PronosticScoringService $scoringService,
        PronosticCsvExportService $csvExportService,
    ): StreamedResponse
    {
        $configuration = $scoringService->resolveScoringConfiguration($request->query->get('mode'));
        $rankings = $snapshotService->capturePreRaceSnapshot($race, $configuration['mode']);

        $raceDate = $race->getRaceDate()?->format('Ymd') ?? 'unknown';
        $filename = sprintf('pronostic-course-%d-%s-%s.csv', $race->getId() ?? 0, $raceDate, $configuration['mode']);

        return $csvExportService->createCsvResponse(
            $filename,
            $csvExportService->raceHeaders(),
            $csvExportService->raceRows($rankings, $configuration['mode'], $configuration['weights'])
        );
    }

    #[Route('/dashboard', name: 'app_management_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, PronosticKpiService $kpiService): Response
    {
        $fromInput = trim((string) $request->query->get('from', ''));
        $toInput = trim((string) $request->query->get('to', ''));

        $from = $this->parseDate($fromInput);
        $to = $this->parseDate($toInput);

        $data = $kpiService->buildDashboard($from, $to);

        return $this->render('manage/dashboard.html.twig', [
            'summary' => $data['summary'],
            'recent' => $data['recent'],
            'filters' => [
                'from' => $from?->format('Y-m-d') ?? $fromInput,
                'to' => $to?->format('Y-m-d') ?? $toInput,
            ],
        ]);
    }

    #[Route('/dashboard/export', name: 'app_management_dashboard_export', methods: ['GET'])]
    public function exportDashboard(
        Request $request,
        PronosticKpiService $kpiService,
        PronosticCsvExportService $csvExportService,
    ): StreamedResponse
    {
        $fromInput = trim((string) $request->query->get('from', ''));
        $toInput = trim((string) $request->query->get('to', ''));

        $from = $this->parseDate($fromInput);
        $to = $this->parseDate($toInput);
        $data = $kpiService->buildDashboard($from, $to);

        $filename = sprintf(
            'dashboard-pronostics-%s-%s.csv',
            $from?->format('Ymd') ?? 'all',
            $to?->format('Ymd') ?? 'all'
        );

        return $csvExportService->createCsvResponse(
            $filename,
            $csvExportService->dashboardHeaders(),
            $csvExportService->dashboardRows($data['recent'])
        );
    }

    #[Route('/data-quality', name: 'app_management_data_quality', methods: ['GET'])]
    public function dataQuality(DataQualityService $dataQualityService): Response
    {
        $report = $dataQualityService->buildReport();

        return $this->render('manage/data_quality.html.twig', [
            'summary' => $report['summary'],
            'participationMetrics' => $report['participation_metrics'],
            'raceMetrics' => $report['race_metrics'],
            'alerts' => $report['alerts'],
        ]);
    }

    private function parseDate(string $date): ?\DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $parsed !== false ? $parsed : null;
    }

    /**
     * @return array{0: ?Race, 1: array<int, string>}
     */
    /**
     * @return array{0: ?Race, 1: array<int, string>}
     */
    private function buildRaceFromRequest(Request $request, EntityManagerInterface $entityManager): array
    {
        $errors = [];
        $raceDateInput = trim((string) $request->request->get('race_date', ''));
        $hippodromeId = (int) $request->request->get('hippodrome_id', 0);
        $meetingNumber = (int) $request->request->get('meeting_number', 0);
        $raceNumber = (int) $request->request->get('race_number', 0);
        $discipline = trim((string) $request->request->get('discipline', ''));
        $sourceDateCode = trim((string) $request->request->get('source_date_code', ''));

        if ($hippodromeId <= 0 || $meetingNumber <= 0 || $raceNumber <= 0) {
            $errors[] = 'Hippodrome, reunion et numero de course sont obligatoires.';
        }

        $raceDate = $this->parseDate($raceDateInput);
        if ($raceDateInput !== '' && $raceDate === null) {
            $errors[] = 'Format de date invalide (attendu: YYYY-MM-DD).';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $hippodrome = $entityManager->getRepository(Hippodrome::class)->find($hippodromeId);
        if (!$hippodrome instanceof Hippodrome) {
            return [null, ['Hippodrome non trouve.']];
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
        [$race, $errors] = $this->buildRaceFromRequest($request, $entityManager);

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
     * @return array{page: int, total_pages: int, total_items: int, per_page: int, offset: int, has_prev: bool, has_next: bool, prev_page: int, next_page: int}
     */
    private function buildPagination(int $totalItems, int $requestedPage, int $perPage): array
    {
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min(max(1, $requestedPage), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'offset' => $offset,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
        ];
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
