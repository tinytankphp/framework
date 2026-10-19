<?php

declare(strict_types=1);

namespace TinyTank\Console;

use TinyTank\Cache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('site:clean', 'Removes the starter examples (demos, sample posts, the sample translation), leaving a clean site.')]
final class SiteCleanCommand extends Command
{
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be removed');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Don\'t ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $examples = new Examples($this->root);
        if (!$examples->present()) {
            $io->success('No examples to remove: this site is already clean (there is no ' . Examples::MANIFEST . ' file).');

            return Command::SUCCESS;
        }
        try {
            $plan = $examples->plan();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section('Deleted');
        $io->listing($plan['delete'] !== [] ? $plan['delete'] : ['(nothing)']);
        $io->section('Edited (example blocks removed)');
        $io->listing($plan['edit'] !== [] ? array_map(static fn (string $file, int $count) => "{$file} ({$count})", array_keys($plan['edit']), $plan['edit']) : ['(nothing)']);

        if ($input->getOption('dry-run')) {
            $io->note('Dry run: nothing was changed.');

            return Command::SUCCESS;
        }
        if (!$input->getOption('force') && !$io->confirm('Remove the examples? Commit your work first: this can\'t be undone without version control.', false)) {
            $io->note('Nothing was changed.');

            return Command::SUCCESS;
        }

        $examples->clean();
        Cache::clear($this->root . '/var/cache');
        $io->success([
            'The examples are gone. Your site starts here.',
            'Next: `npm run build` (or `npm run dev`) and `composer test`; then the "Starting a site" checklist: https://docs.tinytank.dev/1.x/getting-started/checklist',
        ]);

        return Command::SUCCESS;
    }
}
