<?php

namespace App\Command;

use App\Service\DataQualityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:data:quality-report',
    description: 'Affiche un rapport de qualite des donnees scrapees et remonte des alertes',
)]
final class DataQualityReportCommand extends Command
{
    private const PERCENT_FORMAT = '%.1f%%';

    public function __construct(private readonly DataQualityService $dataQualityService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->dataQualityService->buildReport();
        $summary = $report['summary'];

        $io->title('Rapport qualite des donnees scrapees');
        $io->table(
            ['Metrique', 'Valeur'],
            [
                ['Confiance globale', sprintf(self::PERCENT_FORMAT, $summary['global_confidence'] * 100)],
                ['Seuil minimum', sprintf(self::PERCENT_FORMAT, $summary['min_confidence'] * 100)],
                ['Courses scrapees', (string) $summary['scraped_races']],
                ['Participations scrapees', (string) $summary['scraped_participations']],
                ['Dernier import', (string) ($summary['last_imported_at'] ?? '-')],
            ]
        );

        $io->section('Completude participations');
        $io->table(
            ['Champ', 'Completes', 'Total', 'Taux'],
            array_map(
                static fn (array $metric): array => [
                    $metric['label'],
                    (string) $metric['filled'],
                    (string) $metric['total'],
                    sprintf(self::PERCENT_FORMAT, $metric['rate'] * 100),
                ],
                $report['participation_metrics']
            )
        );

        $io->section('Completude courses');
        $io->table(
            ['Champ', 'Completes', 'Total', 'Taux'],
            array_map(
                static fn (array $metric): array => [
                    $metric['label'],
                    (string) $metric['filled'],
                    (string) $metric['total'],
                    sprintf(self::PERCENT_FORMAT, $metric['rate'] * 100),
                ],
                $report['race_metrics']
            )
        );

        if ($report['alerts'] !== []) {
            $io->warning($report['alerts']);
        } else {
            $io->success('Aucune alerte detectee.');
        }

        return ($summary['global_confidence'] < $summary['min_confidence'])
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
