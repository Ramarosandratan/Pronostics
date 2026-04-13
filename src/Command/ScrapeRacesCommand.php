<?php

namespace App\Command;

use App\Service\LetrotScraperService;
use App\Service\RaceWebScraperService;
use App\Service\ScrapedRaceImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:scrape:races',
    description: 'Scrape un site hippique et importe les donnees participants en base',
)]
final class ScrapeRacesCommand extends Command
{
    /**
     * @param array<string, array{url?: string, selectors?: array<string, mixed>}> $sources
     */
    public function __construct(
        private readonly LetrotScraperService $letrotScraper,
        private readonly RaceWebScraperService $scraper,
        private readonly ScrapedRaceImportService $importService,
        #[Autowire('%app.race_scraper.sources%')] private readonly array $sources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Nom de la source configuree dans app.race_scraper.sources')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'URL a scraper (prioritaire sur celle de la source)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Scrape + validation sans ecriture en base')
            ->addOption('hippodrome', null, InputOption::VALUE_REQUIRED, 'Override du nom d hippodrome')
            ->addOption('meeting', null, InputOption::VALUE_REQUIRED, 'Override du numero de reunion')
            ->addOption('race', null, InputOption::VALUE_REQUIRED, 'Override du numero de course')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Override de la date de course (YYYY-MM-DD)')
            ->addOption('letrot-course', null, InputOption::VALUE_REQUIRED, 'Numero de course Letrot (C1, C2...) depuis une URL programme', '1')
            ->addOption('letrot-auto', null, InputOption::VALUE_NONE, 'Mode automatique Letrot: detecte les reunions du jour et importe toutes les courses')
            ->addOption('letrot-date', null, InputOption::VALUE_REQUIRED, 'Date cible Letrot en mode auto (YYYY-MM-DD), par defaut: date du jour')
            ->addOption('letrot-limit-meetings', null, InputOption::VALUE_REQUIRED, 'Limite le nombre de reunions en mode auto (0 = pas de limite)', '0')
            ->addOption('letrot-limit-races', null, InputOption::VALUE_REQUIRED, 'Limite le nombre de courses par reunion en mode auto (0 = pas de limite)', '0')
            ->addOption('force-reimport', null, InputOption::VALUE_NONE, 'Reimporte meme les courses deja presentes en base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ((bool) $input->getOption('letrot-auto')) {
            return $this->runLetrotAuto($input, $io);
        }

        $context = $this->resolveScrapeContext($input);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($context['error'] !== null) {
            $io->error($context['error']);

            return Command::FAILURE;
        }

        return $this->runScrapeImport($input, $io, $context['url'], $context['selectors'], $dryRun);
    }

    private function runLetrotAuto(InputInterface $input, SymfonyStyle $io): int
    {
        $statusCode = Command::SUCCESS;
        $sourceName = (string) ($input->getArgument('source') ?? '');
        if ($sourceName !== '' && $sourceName !== 'letrot') {
            $io->error('Le mode --letrot-auto ne peut etre utilise qu avec la source letrot.');
            $statusCode = Command::FAILURE;
        }

        if ($statusCode !== Command::SUCCESS) {
            return $statusCode;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $targetDate = (string) ($input->getOption('letrot-date') ?: date('Y-m-d'));
        $limitMeetings = max(0, (int) $input->getOption('letrot-limit-meetings'));
        $limitRaces = max(0, (int) $input->getOption('letrot-limit-races'));
        $forceReimport = (bool) $input->getOption('force-reimport');

        $io->title('Scraping automatique Letrot');
        $io->text(sprintf('Date cible: %s', $targetDate));
        $io->text(sprintf('Mode: %s', $dryRun ? 'dry-run (aucune ecriture)' : 'import reel'));

        try {
            $programmeUrls = $this->letrotScraper->discoverProgrammeUrlsForDate($targetDate);
            if ($limitMeetings > 0) {
                $programmeUrls = array_slice($programmeUrls, 0, $limitMeetings);
            }

            if ($programmeUrls === []) {
                $io->warning('Aucune reunion Letrot detectee pour cette date.');
                $statusCode = Command::SUCCESS;
            } else {
                $aggregate = $this->processAutoMeetings($programmeUrls, $limitRaces, $input, $io, $dryRun, $forceReimport);
                $io->success('Scraping automatique Letrot termine.');
                $io->table(
                    ['Metrique', 'Valeur'],
                    [
                        ['Reunions traitees', (string) $aggregate['meetings']],
                        ['Courses traitees', (string) $aggregate['races']],
                        ['Lignes total', (string) $aggregate['rows_total']],
                        ['Lignes importees', (string) $aggregate['rows_imported']],
                        ['Lignes ignorees', (string) $aggregate['rows_skipped']],
                        ['Erreurs import', (string) $aggregate['error_count']],
                        ['Courses creees', (string) $aggregate['races_created']],
                        ['Chevaux crees', (string) $aggregate['horses_created']],
                        ['Personnes creees', (string) $aggregate['persons_created']],
                    ]
                );
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            $statusCode = Command::FAILURE;
        }

        return $statusCode;
    }

    /**
     * @param list<string> $programmeUrls
     *
     * @return array<string, int>
     */
    private function processAutoMeetings(
        array $programmeUrls,
        int $limitRaces,
        InputInterface $input,
        SymfonyStyle $io,
        bool $dryRun,
        bool $forceReimport
    ): array {
        $aggregate = $this->initAutoAggregate();

        foreach ($programmeUrls as $programmeUrl) {
            $meetingStats = $this->processOneMeeting($programmeUrl, $limitRaces, $input, $io, $dryRun, $forceReimport);
            $aggregate['meetings'] += 1;
            $this->mergeStats($aggregate, $meetingStats);
        }

        return $aggregate;
    }

    /**
     * @return array<string, int>
     */
    private function processOneMeeting(
        string $programmeUrl,
        int $limitRaces,
        InputInterface $input,
        SymfonyStyle $io,
        bool $dryRun,
        bool $forceReimport
    ): array {
        $io->section(sprintf('Reunion: %s', $programmeUrl));
        $programmeData = $this->letrotScraper->getProgrammeRaceUrls($programmeUrl);
        $raceUrls = $programmeData['race_urls'];
        if ($limitRaces > 0) {
            $raceUrls = array_slice($raceUrls, 0, $limitRaces);
        }

        $meetingStats = $this->initAutoAggregate();
        foreach ($raceUrls as $raceUrl) {
            $raceData = $this->letrotScraper->scrape($raceUrl);
            $payload = $this->applyOverrides($raceData['payload'], $input);

            $stats = $this->importService->import($payload, $dryRun, $forceReimport);
            $meetingStats['races'] += 1;
            $this->mergeStats($meetingStats, $stats);

            if (!$forceReimport && $stats['rows_imported'] === 0 && $stats['races_created'] === 0) {
                $io->text(sprintf('Course ignoree (payload inchange): %s', $raceUrl));
            } else {
                $io->text(sprintf('Course importee: %s (%d partants)', $raceUrl, $stats['rows_total']));
            }
        }

        return $meetingStats;
    }

    /**
     * @return array<string, int>
     */
    private function initAutoAggregate(): array
    {
        return [
            'meetings' => 0,
            'races' => 0,
            'rows_total' => 0,
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'races_created' => 0,
            'horses_created' => 0,
            'persons_created' => 0,
            'error_count' => 0,
        ];
    }

    /**
     * @param array<string, int> $aggregate
     * @param array<string, int> $stats
     */
    private function mergeStats(array &$aggregate, array $stats): void
    {
        foreach (['races', 'rows_total', 'rows_imported', 'rows_skipped', 'races_created', 'horses_created', 'persons_created', 'error_count'] as $key) {
            $aggregate[$key] += $stats[$key] ?? 0;
        }
    }

    /**
     * @return array{url: string, selectors: array<string, mixed>, error: ?string}
     */
    private function resolveScrapeContext(InputInterface $input): array
    {
        $sourceName = (string) ($input->getArgument('source') ?? '');
        $source = $sourceName !== '' ? ($this->sources[$sourceName] ?? null) : null;
        if ($sourceName !== '' && !is_array($source)) {
            return [
                'url' => '',
                'selectors' => [],
                'error' => sprintf('Source inconnue: %s', $sourceName),
            ];
        }

        $url = '';
        $urlOption = $input->getOption('url');
        if (is_string($urlOption) && trim($urlOption) !== '') {
            $url = trim($urlOption);
        } elseif (is_array($source)) {
            $url = (string) ($source['url'] ?? '');
        }

        $selectors = [];
        if (is_array($source['selectors'] ?? null)) {
            $selectors = $source['selectors'];
        }

        if ($url === '') {
            return [
                'url' => '',
                'selectors' => $selectors,
                'error' => 'Aucune URL fournie. Renseigne --url ou une source avec url.',
            ];
        }

        return [
            'url' => $url,
            'selectors' => $selectors,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $selectors
     */
    private function runScrapeImport(
        InputInterface $input,
        SymfonyStyle $io,
        string $url,
        array $selectors,
        bool $dryRun
    ): int {
        $io->title('Scraping des donnees hippiques');
        $io->text(sprintf('URL: %s', $url));
        $io->text(sprintf('Mode: %s', $dryRun ? 'dry-run (aucune ecriture)' : 'import reel'));

        try {
            $payload = [];
            $provider = (string) ($selectors['provider'] ?? 'generic');

            if ($provider === 'letrot') {
                $letrotCourse = (int) $input->getOption('letrot-course');
                $letrotResult = $this->letrotScraper->scrape($url, $letrotCourse);
                $payload = $letrotResult['payload'];

                $io->text(sprintf('Course Letrot resolue: %s', (string) $letrotResult['race_url']));
                if (is_string($letrotResult['pdf_url']) && $letrotResult['pdf_url'] !== '') {
                    $io->text(sprintf('PDF reunion: %s', $letrotResult['pdf_url']));
                }
            } else {
                $payload = $this->scraper->scrape($url, $selectors);
            }

            $payload = $this->applyOverrides($payload, $input);
            $stats = $this->importService->import($payload, $dryRun, (bool) $input->getOption('force-reimport'));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Scraping et import termines.');
        $io->table(
            ['Metrique', 'Valeur'],
            [
                ['Lignes total', (string) $stats['rows_total']],
                ['Lignes importees', (string) $stats['rows_imported']],
                ['Lignes ignorees', (string) $stats['rows_skipped']],
                ['Erreurs import', (string) $stats['error_count']],
                ['Courses creees', (string) $stats['races_created']],
                ['Chevaux crees', (string) $stats['horses_created']],
                ['Personnes creees', (string) $stats['persons_created']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function applyOverrides(array $payload, InputInterface $input): array
    {
        if (!is_array($payload['race'] ?? null)) {
            $payload['race'] = [];
        }

        foreach (['hippodrome' => 'hippodrome', 'meeting_number' => 'meeting', 'race_number' => 'race', 'race_date' => 'date'] as $field => $option) {
            $value = $input->getOption($option);
            if (is_string($value) && trim($value) !== '') {
                $payload['race'][$field] = trim($value);
            }
        }

        return $payload;
    }
}
