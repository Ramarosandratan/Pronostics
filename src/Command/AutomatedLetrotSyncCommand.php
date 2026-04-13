<?php

namespace App\Command;

use App\Service\DataQualityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:automation:letrot-sync',
    description: 'Pipeline automatise: import Letrot puis controle qualite',
)]
final class AutomatedLetrotSyncCommand extends Command
{
    private const PERCENT_FORMAT = '%.1f%%';

    public function __construct(
        private readonly DataQualityService $dataQualityService,
        #[Autowire('%app.data_quality.min_confidence%')] private readonly float $defaultQualityThreshold,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Date cible Letrot (YYYY-MM-DD), par defaut: aujourd hui')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pipeline en mode validation (sans ecriture)')
            ->addOption('force-reimport', null, InputOption::VALUE_NEGATABLE, 'Force la reimportation des courses deja connues', true)
            ->addOption('skip-quality', null, InputOption::VALUE_NONE, 'Ignore le controle qualite post-import')
            ->addOption('quality-threshold', null, InputOption::VALUE_REQUIRED, 'Seuil de confiance global (0..1)')
            ->addOption('letrot-limit-meetings', null, InputOption::VALUE_REQUIRED, 'Limite reunions (0 = pas de limite)', '0')
            ->addOption('letrot-limit-races', null, InputOption::VALUE_REQUIRED, 'Limite courses/reunion (0 = pas de limite)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $options = $this->resolveOptions($input, $io);

        if ($options === null) {
            return Command::INVALID;
        }

        $this->renderPipelineOptions($io, $options);

        $status = Command::FAILURE;
        $application = $this->getApplication();
        if ($application === null) {
            $io->error('Application console indisponible.');
        } else {
            $scrapeCode = $this->runLetrotScrape($application->find('app:scrape:races'), $options, $output);
            if ($scrapeCode !== Command::SUCCESS) {
                $io->error('Le scraping/import automatique Letrot a echoue.');
            } else {
                $status = $this->runQualityCheck($io, $options);
            }
        }

        return $status;
    }

    private function resolveOptions(InputInterface $input, SymfonyStyle $io): ?array
    {
        $date = trim((string) ($input->getOption('date') ?? ''));
        if ($date === '') {
            $date = (new \DateTimeImmutable())->format('Y-m-d');
        }

        if (\DateTimeImmutable::createFromFormat('Y-m-d', $date) === false) {
            $io->error('Format de date invalide, attendu: YYYY-MM-DD.');

            return null;
        }

        $qualityThreshold = $this->defaultQualityThreshold;
        $thresholdOption = $input->getOption('quality-threshold');
        if (is_string($thresholdOption) && trim($thresholdOption) !== '') {
            $qualityThreshold = (float) $thresholdOption;
        }

        return [
            'date' => $date,
            'dryRun' => (bool) $input->getOption('dry-run'),
            'forceReimport' => (bool) $input->getOption('force-reimport'),
            'skipQuality' => (bool) $input->getOption('skip-quality'),
            'qualityThreshold' => max(0.0, min(1.0, $qualityThreshold)),
            'limitMeetings' => max(0, (int) $input->getOption('letrot-limit-meetings')),
            'limitRaces' => max(0, (int) $input->getOption('letrot-limit-races')),
        ];
    }

    private function renderPipelineOptions(SymfonyStyle $io, array $options): void
    {
        $io->title('Pipeline Letrot automatise');
        $io->table(
            ['Parametre', 'Valeur'],
            [
                ['Date', (string) $options['date']],
                ['Mode', $options['dryRun'] ? 'dry-run' : 'import reel'],
                ['Force reimport', $options['forceReimport'] ? 'oui' : 'non'],
                ['Seuil qualite', sprintf(self::PERCENT_FORMAT, (float) $options['qualityThreshold'] * 100)],
                ['Limite reunions', (string) $options['limitMeetings']],
                ['Limite courses/reunion', (string) $options['limitRaces']],
            ]
        );
    }

    private function runLetrotScrape(Command $scrapeCommand, array $options, OutputInterface $output): int
    {
        $scrapeArgs = [
            'command' => 'app:scrape:races',
            'source' => 'letrot',
            '--letrot-auto' => true,
            '--letrot-date' => (string) $options['date'],
            '--letrot-limit-meetings' => (string) $options['limitMeetings'],
            '--letrot-limit-races' => (string) $options['limitRaces'],
        ];

        if ($options['dryRun']) {
            $scrapeArgs['--dry-run'] = true;
        }

        if ($options['forceReimport']) {
            $scrapeArgs['--force-reimport'] = true;
        }

        $scrapeInput = new ArrayInput($scrapeArgs);
        $scrapeInput->setInteractive(false);

        return $scrapeCommand->run($scrapeInput, $output);
    }

    private function runQualityCheck(SymfonyStyle $io, array $options): int
    {
        if ($options['skipQuality']) {
            $io->success('Pipeline termine (controle qualite ignore).');

            return Command::SUCCESS;
        }

        $report = $this->dataQualityService->buildReport();
        $summary = $report['summary'];
        $confidence = (float) $summary['global_confidence'];
        $qualityThreshold = (float) $options['qualityThreshold'];

        $io->section('Controle qualite post-import');
        $io->table(
            ['Metrique', 'Valeur'],
            [
                ['Confiance globale', sprintf(self::PERCENT_FORMAT, $confidence * 100)],
                ['Seuil minimum', sprintf(self::PERCENT_FORMAT, $qualityThreshold * 100)],
                ['Courses scrapees', (string) $summary['scraped_races']],
                ['Participations scrapees', (string) $summary['scraped_participations']],
                ['Dernier import', (string) ($summary['last_imported_at'] ?? '-')],
            ]
        );

        if ($confidence < $qualityThreshold) {
            $io->warning('Qualite sous seuil configure.');
            if ($report['alerts'] !== []) {
                $io->warning($report['alerts']);
            }

            return Command::FAILURE;
        }

        $io->success('Pipeline termine avec qualite au-dessus du seuil.');

        return Command::SUCCESS;
    }
}
