<?php

declare(strict_types=1);

namespace TinyTank\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TinyTank\Kernel;

#[AsCommand('preview:static', 'Serves the static build locally, as its host will (run build:static first).')]
final class StaticPreviewCommand extends Command
{
    public const ROUTER = __DIR__ . '/../../resources/static/preview-router.php';

    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'The build\'s folder (default: config/app.php static.out, or "deploy")');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port', '8080');
        $this->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Address to listen on (0.0.0.0: reachable from outside a container, as DDEV needs)', '0.0.0.0');
        $this->addOption('php', null, InputOption::VALUE_NONE, 'PHP\'s built-in server instead of Cloudflare\'s (no Node needed; no headers, no forms)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print the command');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Kernel::loadEnv($this->root);
        $config = require $this->root . '/config/app.php';
        $static = is_array($config['static'] ?? null) ? $config['static'] : [];
        $out = (string) ($input->getOption('out') ?? $static['out'] ?? 'deploy');
        $dir = str_starts_with($out, '/') ? $out : $this->root . '/' . $out;
        if (!is_file($dir . '/' . StaticBuildCommand::MARKER)) {
            $io->error("There's no static build in {$out}/: run `php bin/console build:static` first.");

            return Command::FAILURE;
        }
        $port = (string) $input->getOption('port');
        $ip = (string) $input->getOption('ip');
        if (!preg_match('/^\d{2,5}$/', $port) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $io->error('--port is a number and --ip an address, e.g. --port=8080 --ip=127.0.0.1.');

            return Command::INVALID;
        }

        $cloudflare = !$input->getOption('php') && is_file($dir . '/wrangler.jsonc');
        if ($cloudflare) {
            // Cloudflare's local server: _headers, _redirects and the forms' Worker, as on Cloudflare.
            $wrangler = is_file($this->root . '/node_modules/.bin/wrangler')
                ? escapeshellarg($this->root . '/node_modules/.bin/wrangler')
                : 'npx --yes wrangler@4';
            $command = 'cd ' . escapeshellarg($dir) . " && {$wrangler} dev --ip " . escapeshellarg($ip) . ' --port ' . escapeshellarg($port);
            $how = 'Cloudflare\'s local server (wrangler dev): headers, redirects and forms as on Cloudflare; emails are written to '
                . $out . '/.wrangler/tmp/email/ instead of being sent';
        } else {
            $command = 'TINYTANK_STATIC_ROOT=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("{$ip}:{$port}")
                . ' -t ' . escapeshellarg($dir) . ' ' . escapeshellarg((string) realpath(self::ROUTER));
            $how = 'PHP\'s built-in server: pages, files and redirects; not the host\'s headers or forms';
        }

        if ($input->getOption('dry-run')) {
            $output->writeln($command);

            return Command::SUCCESS;
        }
        $io->title('TinyTank static preview');
        $io->writeln(" {$how}.");
        $io->writeln(sprintf(' Open http://localhost:%s (with DDEV: https://<project>.ddev.site:%s). Stop with Ctrl+C.', $port, $port));
        $io->newLine();
        passthru($command, $code);

        return $code === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
