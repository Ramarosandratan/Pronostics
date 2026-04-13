<?php

namespace App\Command;

use App\Service\WinProbabilityModelService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ml:train-win-model',
    description: 'Train the win probability model from historical finished races',
)]
final class TrainWinProbabilityModelCommand extends Command
{
    public function __construct(private readonly WinProbabilityModelService $winProbabilityModelService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->winProbabilityModelService->trainModel();

        $io->title('ML win probability model');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Trained', $result['trained'] ? 'yes' : 'no'],
                ['Samples', (string) $result['samples']],
                ['Positive samples', (string) $result['positive_samples']],
                ['Loss', number_format((float) $result['loss'], 6, '.', '')],
                ['Accuracy', number_format(((float) $result['accuracy']) * 100, 2, '.', '') . '%'],
                ['Model path', $result['model_path']],
            ]
        );

        if (!$result['trained']) {
            $io->warning((string) ($result['reason'] ?? 'Training failed.'));

            return Command::FAILURE;
        }

        $io->success('Model trained and saved successfully.');

        return Command::SUCCESS;
    }
}
