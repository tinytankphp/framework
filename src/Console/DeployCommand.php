<?php

declare(strict_types=1);

namespace TinyTank\Console;

use TinyTank\Blog\Blog;
use TinyTank\Collections\Collections;
use TinyTank\Content\Embeds;
use TinyTank\Pages\Pages;
use TinyTank\Cache;
use TinyTank\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Production build: a list of named steps, run in order. Run it after every code, template or content
 * change. `--list-steps` shows them; `--skip=name,name` leaves some out; the app adds its own with
 * $app->addDeployStep() in config/bootstrap.php.
 *
 * Build first, switch last: the new cache and the published files are built aside, in var/deploy/next/,
 * while the site keeps answering from the current ones; a step that fails leaves the live site as it
 * was. `switch` then moves them in, keeping the previous ones in var/deploy/prev/ for
 * `deploy --rollback`.
 *
 *   assets        (only with --assets) npm run build
 *   composer      composer dump-autoload --optimize --classmap-authoritative
 *   cache         start a new cache, in var/deploy/next/cache/
 *   routes        compile the router
 *   blog          compile the posts and publish their files (for public/media/blog/)
 *   pages         compile the content pages and publish their files (for public/media/pages/)
 *   collections   compile the data collections and publish their items' files (for public/media/collections/)
 *   embeds        download the posters and titles of ::youtube / ::vimeo videos to public/media/embeds/
 *   translations  compile the translation catalogues
 *   templates     compile every Twig template
 *   vite          cache the Vite manifest (missing: the deploy stops, unless the site has no Vite config)
 *   switch        put the new cache and files in place of the live ones (the previous ones: var/deploy/prev/)
 *   opcache       refresh the web server's Opcache (the CLI has its own, so it cannot do this by
 *                 itself), depending on --opcache / APP_OPCACHE:
 *        cachetool  invalidate this project's scripts in PHP-FPM, then precompile them over its
 *                   FastCGI socket (https://github.com/gordalina/cachetool)
 *        reload     run a command that clears Opcache, e.g. `sudo systemctl reload php8.4-fpm`
 *                   or `sudo apachectl graceful` (mod_php); files are recompiled on first use
 *        none       do nothing (shared hosting, or opcache.validate_timestamps=1)
 *   cdn           purge the CDN (config/app.php `cdn`, when it's on and `purge` is set), so it fetches
 *                 the new pages; last, once the server answers with them
 */
#[AsCommand('deploy', 'Optimizes the autoloader, rebuilds all caches and refreshes the web server\'s Opcache.')]
final class DeployCommand extends Command
{
    private const OPCACHE_MODES = ['cachetool', 'reload', 'none'];

    /** The published files, under public/, switched with the cache. */
    public const MEDIA = [Blog::ASSET_URL, Pages::ASSET_URL, Collections::ASSET_URL];

    /** Where the next build is made, and the previous one kept (for --rollback). */
    public const WORK_DIR = '/var/deploy';

    /** @param (\Closure(?bool, array<string, mixed>=): Kernel)|null $boot shared kernel factory from TinyTank\Console\Console */
    public function __construct(private readonly string $root, private readonly ?\Closure $boot = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('opcache', null, InputOption::VALUE_REQUIRED, 'How to refresh Opcache: cachetool, reload or none', getenv('APP_OPCACHE') ?: 'cachetool')
            ->addOption('fcgi', null, InputOption::VALUE_REQUIRED, '[cachetool] PHP-FPM socket or host:port', getenv('APP_FPM_SOCKET') ?: '/run/php-fpm.sock')
            ->addOption('cachetool', null, InputOption::VALUE_REQUIRED, '[cachetool] cachetool binary', getenv('APP_CACHETOOL') ?: 'cachetool')
            ->addOption('reload-cmd', null, InputOption::VALUE_REQUIRED, '[reload] Shell command that clears Opcache', getenv('APP_OPCACHE_RELOAD_CMD') ?: null)
            ->addOption('assets', null, InputOption::VALUE_NONE, 'Run `npm run build` first')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Composer binary', 'composer')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Exclude require-dev packages from the autoloader')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'Comma-separated steps to leave out (see --list-steps)', '')
            ->addOption('list-steps', null, InputOption::VALUE_NONE, 'Show the steps in order, including the app\'s, and exit')
            ->addOption('skip-composer', null, InputOption::VALUE_NONE, 'Same as --skip=composer')
            ->addOption('skip-opcache', null, InputOption::VALUE_NONE, 'Same as --opcache=none')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Put the previous deploy back (its cache and files), then refresh Opcache and purge the CDN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Validate the Opcache settings before changing anything.
        $opcache = $input->getOption('skip-opcache') ? 'none' : (string) $input->getOption('opcache');
        if (!in_array($opcache, self::OPCACHE_MODES, true)) {
            $io->error(sprintf('Unknown --opcache mode "%s". Use one of: %s.', $opcache, implode(', ', self::OPCACHE_MODES)));

            return Command::INVALID;
        }
        $reloadCommand = trim((string) $input->getOption('reload-cmd'));
        if ($opcache === 'reload' && $reloadCommand === '') {
            $io->error('--opcache=reload needs --reload-cmd (or APP_OPCACHE_RELOAD_CMD), e.g. "sudo systemctl reload php8.4-fpm".');

            return Command::INVALID;
        }

        // Booted first (in production mode) so config/bootstrap.php can add its steps; it builds the next
        // cache aside, so the live site keeps answering from the current one until `switch`.
        $live = $this->liveCacheDir();
        $next = ['cache_dir' => $this->root . self::WORK_DIR . '/next/cache'];
        $app = $this->boot !== null ? ($this->boot)(false, $next) : Kernel::boot($this->root, false, $next);
        if ($app->debug) {
            $io->error('The kernel was already booted in debug mode; run deploy on its own.');

            return Command::FAILURE;
        }
        try {
            $steps = $this->steps($app, $input, $output, $opcache, $reloadCommand, $live);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $skip = array_filter(array_map('trim', explode(',', (string) $input->getOption('skip'))));
        if ($input->getOption('skip-composer')) {
            $skip[] = 'composer';
        }
        if ($unknown = array_diff($skip, array_keys($steps))) {
            $io->error(sprintf('Unknown step(s) in --skip: %s. Steps: %s.', implode(', ', $unknown), implode(', ', array_keys($steps))));

            return Command::INVALID;
        }

        if ($input->getOption('list-steps')) {
            $io->table(['Step', 'What it does'], array_map(
                static fn (string $name, array $step) => [$name . (in_array($name, $skip, true) ? ' (skipped)' : ''), $step['description']],
                array_keys($steps),
                $steps,
            ));

            return Command::SUCCESS;
        }

        if ($input->getOption('rollback')) {
            return $this->rollback($steps, $skip, $live, $io);
        }

        $io->title('TinyTank deploy');
        if ((new Examples($this->root))->present()) {
            $io->warning('This site still has TinyTank\'s starter examples (demos, sample posts, the sample translation). Remove them with `php bin/console site:clean`.');
        }
        foreach ($steps as $name => $step) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            if ($step['run']() === false) {
                $io->error("Deploy stopped at step \"{$name}\".");

                return Command::FAILURE;
            }
        }
        if ($opcache === 'none' && !in_array('opcache', $skip, true)) {
            $io->note('Opcache was not refreshed. With opcache.validate_timestamps=0, reload PHP yourself.');
        }

        $io->success('Deployed.');

        return Command::SUCCESS;
    }

    /**
     * The built-in steps, with the app's steps (Kernel::addDeployStep) inserted where they asked.
     *
     * @return array<string, array{description: string, run: \Closure(): mixed}> run() returning false stops the deploy
     */
    private function steps(Kernel $app, InputInterface $input, OutputInterface $output, string $opcache, string $reloadCommand, string $live): array
    {
        $io = new SymfonyStyle($input, $output);
        $next = $this->root . self::WORK_DIR . '/next';
        $steps = [];
        if ($input->getOption('assets')) {
            $steps['assets'] = ['description' => 'npm run build', 'run' => function () use ($io) {
                $io->section('Building assets');

                return $this->exec('npm run build', $io);
            }];
        }
        $steps['composer'] = ['description' => 'Optimize the Composer autoloader (authoritative class map)', 'run' => function () use ($io, $input) {
            $io->section('Optimizing Composer autoloader');

            return $this->exec(
                escapeshellarg((string) $input->getOption('composer'))
                . ' dump-autoload --optimize --classmap-authoritative --no-interaction'
                . ($input->getOption('no-dev') ? ' --no-dev' : ''),
                $io,
            );
        }];
        $steps['cache'] = ['description' => 'Start a new cache, aside (var/deploy/next/)', 'run' => static function () use ($io, $app, $next) {
            $io->section('Building the next cache (var/deploy/next/)');
            // What an earlier deploy that failed left behind.
            self::remove($next);
            // Later steps (including the app's) may write straight into it.
            foreach ([$app->cacheDir, ...array_map(static fn (string $url) => "{$next}/public{$url}", self::MEDIA)] as $dir) {
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Cannot create {$dir}.");
                }
            }
        }];
        $steps['routes'] = ['description' => 'Compile the routes', 'run' => static function () use ($io, $app) {
            $app->router->warmup();
            $io->writeln(sprintf(' ✔ %d routes compiled', count($app->router->routes())));
        }];
        $steps['blog'] = ['description' => 'Compile the blog posts and publish their files (for public' . Blog::ASSET_URL . '/)', 'run' => static function () use ($io, $app, $next) {
            [$posts, $versions] = $app->blog->warmup();
            $io->writeln(sprintf(' ✔ %d blog posts compiled (%d language versions)', $posts, $versions));
            $io->writeln(sprintf(' ✔ %d post files published for public%s/', $app->blog->publishAssets("{$next}/public"), Blog::ASSET_URL));
        }];
        $steps['pages'] = ['description' => 'Compile the content pages and publish their files (for public' . Pages::ASSET_URL . '/)', 'run' => static function () use ($io, $app, $next) {
            [$pages, $versions] = $app->pages->warmup();
            $shadowed = $app->shadowedPages();
            if ($shadowed !== []) {
                foreach ($shadowed as $uri => $route) {
                    $io->error("The content page at /{$uri} can never be shown: the route \"{$route}\" answers it first. Rename the page's folder (or its translated slug) or the route.");
                }

                return false;
            }
            $io->writeln(sprintf(' ✔ %d content pages compiled (%d language versions)', $pages, $versions));
            $io->writeln(sprintf(' ✔ %d page files published for public%s/', $app->pages->publishAssets("{$next}/public"), Pages::ASSET_URL));
        }];
        $steps['collections'] = ['description' => 'Compile the data collections and publish their item folders\' files (for public' . Collections::ASSET_URL . '/)', 'run' => static function () use ($io, $app, $next) {
            $counts = $app->collections->warmup();
            $io->writeln(sprintf(' ✔ %d data collection%s compiled (%d items)', count($counts), count($counts) === 1 ? '' : 's', array_sum($counts)));
            $io->writeln(sprintf(' ✔ %d item files published for public%s/', $app->collections->publishAssets("{$next}/public"), Collections::ASSET_URL));
        }];
        $steps['embeds'] = ['description' => 'Download the posters of embedded videos to public' . Embeds::URL . '/', 'run' => static function () use ($io, $app) {
            [$videos, $fetched, $failed] = self::fetchEmbeds($app);
            $io->writeln(sprintf(' ✔ %d embedded video%s (%d new poster%s downloaded)', $videos, $videos === 1 ? '' : 's', $fetched, $fetched === 1 ? '' : 's'));
            if ($failed !== []) {
                // Not fatal: those videos show a neutral poster until the next deploy manages to download theirs.
                $io->warning('No poster for: ' . implode(', ', $failed) . ' (offline, or the video is private or gone).');
            }
        }];
        $steps['translations'] = ['description' => 'Compile the translation catalogues', 'run' => static function () use ($io, $app) {
            $io->writeln(sprintf(' ✔ translations compiled for %d languages', $app->translations->warmup()));
        }];
        $steps['templates'] = ['description' => 'Compile every Twig template', 'run' => function () use ($io, $app) {
            $io->writeln(sprintf(' ✔ %d Twig templates compiled', $this->compileTemplates($app)));
        }];
        $steps['vite'] = ['description' => 'Cache the Vite manifest', 'run' => function () use ($io, $app) {
            if ($app->vite->warmup()) {
                $io->writeln(' ✔ Vite manifest cached');

                return true;
            }
            // Every page needs it: without it they all fail ("Vite manifest not found").
            if (glob($this->root . '/vite.config.{js,mjs,cjs,ts,mts,cts}', GLOB_BRACE) !== []) {
                $io->error('public/build/.vite/manifest.json is missing, and every page needs it: run `npm run build` first (or deploy --assets).');

                return false;
            }
            $io->note('No Vite build (and no vite.config.js): pages are served without built assets.');

            return true;
        }];
        $steps['switch'] = ['description' => 'Put the new cache and files in place of the live ones (the previous ones in var/deploy/prev/)', 'run' => function () use ($io, $live, $next) {
            $io->section('Switching to the new build');
            $prev = $this->root . self::WORK_DIR . '/prev';
            self::remove($prev);
            // The files first: the new pages may show new images, and old pages still have theirs until then.
            foreach (self::MEDIA as $url) {
                self::swap($this->root . "/public{$url}", "{$next}/public{$url}", "{$prev}/public{$url}");
            }
            if (is_file("{$live}/.gitkeep")) {
                copy("{$live}/.gitkeep", "{$next}/cache/.gitkeep"); // committed in the skeleton
            }
            self::swap($live, "{$next}/cache", "{$prev}/cache");
            self::remove($next);
            $io->writeln(' ✔ The site now answers from the new build (the previous one is kept for deploy --rollback)');
        }];
        $steps['opcache'] = ['description' => "Refresh the web server's Opcache ({$opcache})", 'run' => fn () => match ($opcache) {
            'cachetool' => $this->refreshWithCachetool($input, $live, $io),
            'reload' => $this->refreshWithReload($reloadCommand, $io),
            default => true, // 'none' (the mode was validated before any step ran)
        }];
        $steps['cdn'] = ['description' => 'Purge the CDN, when CDN caching is on', 'run' => static function () use ($io, $app) {
            if (!$app->cdn->enabled) {
                return true;
            }
            if (!$app->cdn->purgeable()) {
                $io->note(sprintf('The CDN was not purged (no CDN_PURGE): changes reach it within %d seconds (cdn.ttl).', $app->cdn->ttl()));

                return true;
            }
            try {
                $purger = $app->cdn->purger();
                $purger->purgeAll();
            } catch (\Throwable $e) {
                $io->error("The site is deployed, but the CDN still has the old pages: {$e->getMessage()} Fix it, then run bin/console cdn:purge --all.");

                return false;
            }
            $io->writeln(" ✔ CDN purged ({$purger->name()})");

            return true;
        }];

        foreach ($app->deploySteps() as $custom) {
            $name = $custom['name'];
            if (isset($steps[$name])) {
                throw new \InvalidArgumentException("Deploy step \"{$name}\" already exists.");
            }
            $anchor = $custom['before'] ?? $custom['after'] ?? 'switch';
            if (!isset($steps[$anchor])) {
                throw new \InvalidArgumentException("Deploy step \"{$name}\" refers to unknown step \"{$anchor}\". Steps: " . implode(', ', array_keys($steps)) . '.');
            }
            $step = $custom['step'];
            $entry = [
                'description' => $custom['description'] !== '' ? $custom['description'] : (is_string($step) ? "bin/console {$step}" : 'App step'),
                'run' => function () use ($step, $name, $app, $io, $output) {
                    $io->section("App step: {$name}");
                    if (is_string($step)) {
                        $console = $this->getApplication() ?? throw new \LogicException('deploy must run inside the console application.');

                        return $console->find($step)->run(new ArrayInput([]), $output) === Command::SUCCESS;
                    }

                    return $step($app, $io);
                },
            ];
            $position = array_search($anchor, array_keys($steps), true) + ($custom['after'] !== null ? 1 : 0);
            $steps = array_slice($steps, 0, $position, true) + [$name => $entry] + array_slice($steps, $position, null, true);
        }

        return $steps;
    }

    /**
     * `deploy --rollback`: the previous build back in place (and the current one kept as the previous,
     * so a second rollback undoes the first), then Opcache and the CDN as after a deploy.
     *
     * @param array<string, array{description: string, run: \Closure(): mixed}> $steps
     * @param array<string> $skip
     */
    private function rollback(array $steps, array $skip, string $live, SymfonyStyle $io): int
    {
        $prev = $this->root . self::WORK_DIR . '/prev';
        if (!is_dir("{$prev}/cache")) {
            $io->error('There\'s no previous deploy to go back to (var/deploy/prev/ is empty): deploy keeps one from the second deploy on.');

            return Command::FAILURE;
        }
        $io->title('TinyTank deploy --rollback');
        $current = $this->root . self::WORK_DIR . '/current';
        self::remove($current);
        foreach ([...array_map(fn (string $url) => [$this->root . "/public{$url}", "{$prev}/public{$url}", "{$current}/public{$url}"], self::MEDIA), [$live, "{$prev}/cache", "{$current}/cache"]] as [$to, $from, $keep]) {
            if (is_dir($from)) {
                self::swap($to, $from, $keep);
            }
        }
        self::remove($prev);
        rename($current, $prev);
        $io->writeln(' ✔ The site answers from the previous build again (deploy --rollback again undoes this)');

        foreach (['opcache', 'cdn'] as $name) {
            if (!in_array($name, $skip, true) && $steps[$name]['run']() === false) {
                $io->error("Rolled back, but step \"{$name}\" failed.");

                return Command::FAILURE;
            }
        }
        $io->success('Rolled back.');

        return Command::SUCCESS;
    }

    /**
     * Moves $next to $live, and what was live to $prev: two renames, so the site is never half-built.
     * In between, a request may find no cache and start a new one (production builds what's missing):
     * that's set aside, and the move tried again.
     */
    private static function swap(string $live, string $next, string $prev): void
    {
        foreach ([dirname($prev), dirname($live)] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create {$dir}.");
            }
        }
        if (file_exists($live) && !rename($live, $prev)) {
            throw new \RuntimeException("Cannot move {$live} aside (to {$prev}).");
        }
        for ($attempt = 1; !@rename($next, $live); ++$attempt) {
            if ($attempt === 5 || !file_exists($live)) {
                throw new \RuntimeException("Cannot move {$next} to {$live}: are they on the same disk? The previous build is in {$prev}.");
            }
            $stray = "{$prev}.stray";
            self::remove($stray);
            rename($live, $stray);
            self::remove($stray);
        }
    }

    /** Deletes a folder and everything in it (nothing if it doesn't exist). */
    private static function remove(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
        } elseif (is_dir($dir)) {
            Cache::clear($dir);
            @unlink("{$dir}/.gitkeep");
            rmdir($dir);
        }
    }

    /** var/cache, or the site's own `cache_dir` (config/app.php). */
    private function liveCacheDir(): string
    {
        $config = require $this->root . '/config/app.php';

        return is_array($config) && is_string($config['cache_dir'] ?? null) ? $config['cache_dir'] : $this->root . '/var/cache';
    }

    /**
     * Every ::youtube / ::vimeo video in posts, pages and collections (any language): downloads the
     * posters still missing.
     *
     * @return array{int, int, list<string>} videos, posters downloaded, videos without a poster
     */
    public static function fetchEmbeds(Kernel $app): array
    {
        $videos = [];
        foreach (array_keys($app->site->languages) as $language) {
            $queries = [$app->posts(), $app->pages()];
            foreach (array_keys($app->collections->schemas) as $name) {
                $queries[] = $app->collection($name);
            }
            foreach ($queries as $query) {
                foreach ($query->language($language)->all() as $item) {
                    foreach ((array) ($item['components'] ?? []) as $component) {
                        if (is_array($component) && isset(Embeds::PROVIDERS[$component['name'] ?? '']) && is_string($component['args']['id'] ?? null)) {
                            $videos["{$component['name']}:{$component['args']['id']}"] = [$component['name'], $component['args']['id']];
                        }
                    }
                }
            }
        }
        $fetched = 0;
        $failed = [];
        foreach ($videos as $key => [$provider, $id]) {
            if (!$app->embeds->missing($provider, $id)) {
                continue;
            }
            $app->embeds->fetch($provider, $id) ? ++$fetched : $failed[] = $key;
        }

        return [count($videos), $fetched, $failed];
    }

    /** Compiles every template the site can use: its own, packages' and the framework's (overridden ones once). */
    private function compileTemplates(Kernel $app): int
    {
        $loader = $app->twig->getLoader();
        $names = [];
        foreach ($loader instanceof \Twig\Loader\FilesystemLoader ? $loader->getPaths() : [$this->root . '/templates'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'twig') {
                    $names[str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1))] = true;
                }
            }
        }
        foreach (array_keys($names) as $name) {
            $app->twig->load($name);
        }

        return count($names);
    }

    /** Invalidates this project's scripts in PHP-FPM's Opcache, then precompiles them all. */
    private function refreshWithCachetool(InputInterface $input, string $cacheDir, SymfonyStyle $io): bool
    {
        $io->section('Refreshing PHP-FPM Opcache (cachetool)');
        $cachetool = escapeshellarg((string) $input->getOption('cachetool'));
        $fcgi = ' --quiet --fcgi=' . escapeshellarg((string) $input->getOption('fcgi'));

        $commands = [
            // Connection check: invalidate/compile exit with 0 even when they cannot reach PHP-FPM.
            "{$cachetool} opcache:status{$fcgi}",
            // Drop stale entries first: with opcache.validate_timestamps=0, FPM never notices changed files.
            "{$cachetool} opcache:invalidate:scripts --force{$fcgi} " . escapeshellarg((string) realpath($this->root)),
        ];
        foreach ($this->opcacheDirs($cacheDir) as $dir) {
            $commands[] = "{$cachetool} opcache:compile:scripts --batch{$fcgi} " . escapeshellarg($dir);
        }
        $commands[] = "{$cachetool} opcache:compile:script{$fcgi} " . escapeshellarg($this->root . '/public/index.php');

        foreach ($commands as $command) {
            if (!$this->exec($command, $io)) {
                $io->note([
                    'Is cachetool installed (see README) and does --fcgi point at the PHP-FPM socket?',
                    'The deploy user also needs access to the socket (usually group www-data).',
                    'Without PHP-FPM, use --opcache=reload or --opcache=none.',
                ]);

                return false;
            }
        }

        return true;
    }

    /** Runs the configured command (a graceful PHP-FPM or Apache reload), which empties Opcache. */
    private function refreshWithReload(string $command, SymfonyStyle $io): bool
    {
        $io->section('Refreshing Opcache (reload)');
        // The command comes from the deploy configuration (option or APP_OPCACHE_RELOAD_CMD), never from a request.
        if (!$this->exec($command, $io)) {
            $io->note('The deploy user may need sudo rights for this command only (see README).');

            return false;
        }

        return true;
    }

    /** @return list<string> directories holding every PHP file a request can load */
    private function opcacheDirs(string $cacheDir): array
    {
        // dirname(__DIR__) is the framework itself (lib/src), symlinked into vendor/ by Composer.
        $dirs = [dirname(__DIR__), $this->root . '/src', $this->root . '/config', $this->root . '/vendor', $cacheDir];

        return array_values(array_unique(array_filter(array_map('realpath', $dirs))));
    }

    private function exec(string $command, SymfonyStyle $io): bool
    {
        $io->writeln(" <comment>$ {$command}</comment>");
        passthru('cd ' . escapeshellarg($this->root) . ' && ' . $command, $code);
        if ($code !== 0) {
            $io->error("Command failed with exit code {$code}.");

            return false;
        }

        return true;
    }
}
