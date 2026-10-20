<?php

declare(strict_types=1);

namespace TinyTank\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TinyTank\Kernel;

/**
 * Builds the static site and publishes it on Cloudflare Workers, without a browser login: wrangler
 * reads CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID from the environment (.env). Forms sent over
 * SMTP get their server's settings as the Worker's encrypted secrets, from .env too: only the SMTP_*
 * keys, handed to wrangler on its standard input (never in a file, nor in the build).
 */
#[AsCommand('publish:static', 'Builds the static site and publishes it on Cloudflare (CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID in .env).')]
final class StaticPublishCommand extends Command
{
    /** The Worker's secrets for forms sent over SMTP; SMTP_HOST is required. */
    public const SMTP_SECRETS = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD'];

    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-build', null, InputOption::VALUE_NONE, 'Publish the existing build');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only say what would be done (nothing is built, uploaded or written)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Kernel::loadEnv($this->root);
        $dry = (bool) $input->getOption('dry-run');

        $missing = array_filter(['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID'], static fn (string $key) => (string) getenv($key) === '');
        if ($missing !== []) {
            $io->error(implode(' and ', $missing) . ' must be set (in .env): a Cloudflare API token from the "Edit Cloudflare Workers" template, and your account ID. See the docs, Static Site Generator → Publishing.');

            return Command::INVALID;
        }

        if (!$input->getOption('no-build') && !$dry) {
            $build = $this->getApplication()?->find('build:static') ?? throw new \LogicException('No build:static command.');
            if ($build->run(new ArrayInput([]), $output) !== Command::SUCCESS) {
                return Command::FAILURE;
            }
        }
        $config = require $this->root . '/config/app.php';
        $out = (string) ((is_array($config['static'] ?? null) ? $config['static'] : [])['out'] ?? 'deploy');
        $dir = str_starts_with($out, '/') ? $out : $this->root . '/' . $out;
        if (!$dry && !is_file($dir . '/wrangler.jsonc')) {
            $io->error("There's no build for Cloudflare in {$out}/ (wrangler.jsonc): run `php bin/console build:static` (host cloudflare).");

            return Command::FAILURE;
        }

        // Forms sent over SMTP: their server's settings, as the Worker's secrets.
        $smtp = is_file($dir . '/worker.js') && str_contains((string) file_get_contents($dir . '/worker.js'), '"send": "smtp"');
        $secrets = [];
        if ($smtp) {
            foreach (self::SMTP_SECRETS as $key) {
                if ((string) getenv($key) !== '') {
                    $secrets[$key] = (string) getenv($key);
                }
            }
            // Dotenv replaces $NAME in unquoted and double-quoted values: a password with a $ arrives changed.
            $env = is_file($this->root . '/.env') ? (string) file_get_contents($this->root . '/.env') : '';
            foreach (array_keys($secrets) as $key) {
                if (preg_match('/^' . $key . '=(?!\')[^\n]*\$/m', $env) === 1) {
                    $io->warning("{$key} in .env has a \$ outside single quotes, which .env reads as a variable: write it as {$key}='…' so it's used as written.");
                }
            }
            if (!isset($secrets['SMTP_HOST'])) {
                $io->error('A form is sent over SMTP: set SMTP_HOST (and SMTP_PORT, SMTP_USER, SMTP_PASSWORD) in .env.');

                return Command::INVALID;
            }
        }

        // The secrets after the deploy, on wrangler's input (`deploy --secrets-file` would need them in a file).
        $cd = 'cd ' . escapeshellarg($dir) . ' && ' . StaticPreviewCommand::wrangler($this->root);
        if ($dry) {
            $io->writeln($cd . ' deploy');
            $io->writeln($secrets !== [] ? $cd . ' secret bulk, with ' . implode(', ', array_keys($secrets)) . ' (values not shown)' : 'No secrets.');

            return Command::SUCCESS;
        }

        $io->section('Publishing on Cloudflare');
        passthru($cd . ' deploy', $code);
        if ($code !== 0) {
            $io->error('wrangler deploy failed (its message is above). "No access to the specified resource" or "Authentication error": the API token lacks a permission; make it from the "Edit Cloudflare Workers" template.');

            return Command::FAILURE;
        }
        if ($secrets !== []) {
            $io->writeln(' Secrets for the forms: ' . implode(', ', array_keys($secrets)) . '.');
            $process = proc_open($cd . ' secret bulk', [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not run wrangler.');
            }
            fwrite($pipes[0], json_encode($secrets, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            if (proc_close($process) !== 0) {
                $io->error('The site is published, but its forms\' secrets were refused (wrangler\'s message is above): run publish:static --no-build again.');

                return Command::FAILURE;
            }
        }
        $io->success('Published.');

        return Command::SUCCESS;
    }
}
