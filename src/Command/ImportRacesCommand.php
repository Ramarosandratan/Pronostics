<?php

namespace App\Command;

use App\Service\ExcelImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:races',
    description: 'Importe les donnees hippiques depuis un fichier Excel (.xlsx) vers PostgreSQL',
)]
class ImportRacesCommand extends Command
{
    public function __construct(private readonly ExcelImportService $importService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Chemin vers le fichier .xlsx', 'pbet_res.xlsx')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valide le parsing sans ecrire en base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Import des resultats hippiques');
        $io->text(sprintf('Fichier: %s', $file));
        $io->text(sprintf('Mode: %s', $dryRun ? 'dry-run (aucune ecriture)' : 'import reel'));

        try {
            $stats = $this->importService->import($file, $dryRun);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Import termine.');
        $io->table(
            ['Metrique', 'Valeur'],
            [
                ['Lignes total', (string) $stats['rows_total']],
                ['Lignes importees', (string) $stats['rows_imported']],
                ['Lignes ignorees', (string) $stats['rows_skipped']],
                ['Courses creees', (string) $stats['races_created']],
                ['Chevaux crees', (string) $stats['horses_created']],
                ['Personnes creees', (string) $stats['persons_created']],
            ]
        );

        return Command::SUCCESS;
    }
}
