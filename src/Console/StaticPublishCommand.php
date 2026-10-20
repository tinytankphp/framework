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
 * keys, through a temporary file deleted right after, never written in the build.
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
            $io->error(implode(' and ', $missing) . ' must be set (in .env): a Cloudflare API token from the "Edit Cloudflare Workers" template, and your account ID. See the docs, Static builds → Publishing.');

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
            if (!isset($secrets['SMTP_HOST'])) {
                $io->error('A form is sent over SMTP: set SMTP_HOST (and SMTP_PORT, SMTP_USER, SMTP_PASSWORD) in .env.');

                return Command::INVALID;
            }
        }

        $file = $this->root . '/var/static-secrets.env';
        $command = 'cd ' . escapeshellarg($dir) . ' && ' . StaticPreviewCommand::wrangler($this->root) . ' deploy' . ($secrets !== [] ? ' --secrets-file ' . escapeshellarg($file) : '');
        if ($dry) {
            $io->writeln($command);
            $io->writeln($secrets !== [] ? ' with the secrets ' . implode(', ', array_keys($secrets)) . ' (values not shown)' : ' without secrets');

            return Command::SUCCESS;
        }

        $io->section('Publishing on Cloudflare');
        try {
            if ($secrets !== []) {
                if (!is_dir(dirname($file))) {
                    mkdir(dirname($file), 0775, true);
                }
                touch($file);
                chmod($file, 0600);
                file_put_contents($file, implode('', array_map(static fn (string $key, string $value) => $key . '=' . self::quote($value) . "\n", array_keys($secrets), $secrets)));
                $io->writeln(' Secrets for the forms: ' . implode(', ', array_keys($secrets)) . '.');
            }
            passthru($command, $code);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($code !== 0) {
            $io->error('wrangler deploy failed (its message is above).');

            return Command::FAILURE;
        }
        $io->success('Published.');

        return Command::SUCCESS;
    }

    /** A value for an .env file, whatever it contains. */
    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], $value) . '"';
    }
}
