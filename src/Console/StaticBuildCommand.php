<?php

declare(strict_types=1);

namespace TinyTank\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TinyTank\Cache;
use TinyTank\Kernel;
use TinyTank\Static\Builder;

#[AsCommand('build:static', 'Renders the whole site into a folder of plain files, for a static host.')]
final class StaticBuildCommand extends Command
{
    /** The output marks itself, so a later build only ever empties a folder it wrote. */
    public const MARKER = '.tinytank-static';

    /** The site's own folders: never an output. */
    private const PROTECTED = ['bin', 'config', 'content', 'public', 'resources', 'src', 'templates', 'tests', 'translations', 'var', 'vendor', 'node_modules', '.git'];

    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output folder (default: config/app.php static.out, or "deploy")');
        $this->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'The site\'s public address (default: static.base_url, or APP_URL)');
        $this->addOption('allow-dynamic', null, InputOption::VALUE_NONE, 'Succeed even when pages use features that need the server (they won\'t work)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Kernel::loadEnv($this->root);
        $config = require $this->root . '/config/app.php';
        $static = is_array($config['static'] ?? null) ? $config['static'] : [];

        $out = (string) ($input->getOption('out') ?? $static['out'] ?? 'deploy');
        $baseUrl = rtrim((string) ($input->getOption('base-url') ?? $static['base_url'] ?? $config['url'] ?? ''), '/');
        if (!preg_match('#^https?://[^/\s]+$#', $baseUrl)) {
            $io->error("The base URL must be the site's address, like https://example.com (got \"{$baseUrl}\"): set static.base_url in config/app.php or pass --base-url.");

            return Command::INVALID;
        }
        try {
            $out = $this->prepare($out);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        if (!is_file($this->root . '/public/build/.vite/manifest.json')) {
            $io->error('public/build/.vite/manifest.json is missing: run `npm run build` first.');

            return Command::FAILURE;
        }

        $io->title('TinyTank static build');
        // Its own cache: development's var/cache stays as it is.
        $cache = $this->root . '/var/static-cache';
        Cache::clear($cache);
        $app = Kernel::boot($this->root, false, ['url' => $baseUrl, 'cache_dir' => $cache]);
        [$videos, $fetched, $failed] = DeployCommand::fetchEmbeds($app);
        if ($failed !== []) {
            $io->warning('No poster for: ' . implode(', ', $failed) . ' (offline, or the video is private or gone).');
        }
        $paths = array_values(array_filter((array) ($static['paths'] ?? []), 'is_string'));
        $build = (new Builder($app, $out, $paths))->run();

        $relative = str_starts_with($out, $this->root . '/') ? substr($out, strlen($this->root) + 1) : $out;
        $io->writeln(sprintf(' ✔ %d pages written to %s/ for %s', count($build->pages), $relative, $baseUrl));
        $io->writeln(sprintf(' ✔ %d files copied and published (assets, media, public/), %d video posters', $build->files, $videos));
        if ($build->redirects !== []) {
            $io->writeln(sprintf(' ✔ %d redirects found (for the host\'s rules)', count($build->redirects)));
        }

        if ($build->dynamic !== []) {
            $lines = [];
            foreach ($build->dynamic as $path => $features) {
                foreach ($features as $feature) {
                    $lines[] = "{$path}: {$feature}";
                }
            }
            $message = ['These need the server, so they won\'t work on the static site:', ...$lines, 'Leave them out with {% if not site.static %} in the templates, or give them a static version.'];
            $input->getOption('allow-dynamic') ? $io->warning($message) : $io->error([...$message, 'Pass --allow-dynamic to build anyway.']);
        }
        if ($build->errors !== []) {
            $io->error(['Broken:', ...array_map(static fn (string $path, string $error) => "{$path} {$error}", array_keys($build->errors), $build->errors)]);

            return Command::FAILURE;
        }
        if ($build->dynamic !== [] && !$input->getOption('allow-dynamic')) {
            return Command::FAILURE;
        }
        $io->success("The static site is in {$relative}/.");

        return Command::SUCCESS;
    }

    /** An absolute path with its "." and ".." resolved, without following links. */
    private static function resolve(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $part === '..' ? array_pop($parts) : $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    /** The output folder, emptied (only if it's a previous build's), with its marker. */
    private function prepare(string $out): string
    {
        $path = self::resolve(str_starts_with($out, '/') ? $out : $this->root . '/' . $out);
        $root = self::resolve($this->root);
        if ($path === $root || str_starts_with($root . '/', $path . '/')) {
            throw new \RuntimeException("The output folder can't be the site or contain it ({$out}).");
        }
        if (str_starts_with($path, $root . '/') && in_array(explode('/', substr($path, strlen($root) + 1))[0], self::PROTECTED, true)) {
            throw new \RuntimeException("The output folder can't be inside the site's own " . explode('/', substr($path, strlen($root) + 1))[0] . "/ folder ({$out}): use a folder of its own, like deploy.");
        }
        if (is_dir($path) && !is_file($path . '/' . self::MARKER) && (scandir($path) ?: []) !== ['.', '..']) {
            throw new \RuntimeException("{$out}/ already has files that a static build didn't write: empty it or choose another --out.");
        }
        Cache::clear($path);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException("Cannot create {$out}/.");
        }
        file_put_contents($path . '/' . self::MARKER, "Written by `bin/console build:static`: the next build empties this folder.\n");

        return $path;
    }
}
