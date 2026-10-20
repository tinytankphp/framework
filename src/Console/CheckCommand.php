<?php

declare(strict_types=1);

namespace TinyTank\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TinyTank\Images\Images;
use TinyTank\Kernel;

/**
 * `bin/console check`: is this server and this site ready to deploy? Read-only: content and templates
 * are compiled into a temporary folder, the live site is never touched. `deploy` runs the same checks
 * first. Errors stop a deploy; warnings are worth a look.
 *
 *   php           the PHP version and the extensions TinyTank needs
 *   images        GD or Imagick (to publish images without their metadata), AVIF and WebP
 *   environment   APP_SECRET and APP_URL
 *   writable      the folders deploy and the site write to
 *   content       posts, pages, collections, components, translations and routes compile
 *   templates     every Twig template compiles; each page's template, and its form, exist
 *   pages         no content page is hidden behind a route
 *   vite          the Vite build exists and has the entry points templates ask for
 */
#[AsCommand('check', 'Checks this server and the site are ready to deploy, without changing anything.')]
final class CheckCommand extends Command
{
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'The results as JSON (for CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cache = sys_get_temp_dir() . '/tinytank-check-' . bin2hex(random_bytes(6));
        try {
            $app = Kernel::boot($this->root, false, ['cache_dir' => $cache]);
            $results = self::checks($app, $this->root);
        } catch (\Throwable $e) {
            $results = [['check' => 'boot', 'status' => 'error', 'message' => 'The site doesn\'t start: ' . $e->getMessage()]];
        } finally {
            self::remove($cache);
        }
        $failed = array_filter($results, static fn (array $result) => $result['status'] === 'error') !== [];

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['ok' => !$failed, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->section('Checking the server and the site');
            self::print($results, $io);
            $failed ? $io->error('Not ready to deploy: fix the errors above.') : $io->success('Ready to deploy.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param list<array{check: string, status: string, message: string}> $results */
    public static function print(array $results, SymfonyStyle $io): void
    {
        foreach ($results as $result) {
            $mark = match ($result['status']) {
                'ok' => '<info>✔</info>',
                'warning' => '<comment>!</comment>',
                default => '<error>✘</error>',
            };
            $io->writeln(" {$mark} <options=bold>{$result['check']}</>: {$result['message']}");
        }
    }

    /**
     * Every check, on a production kernel: its cache folder gets the compiled content and templates.
     *
     * @return list<array{check: string, status: string, message: string}>
     */
    public static function checks(Kernel $app, string $root): array
    {
        $results = [];
        $add = static function (string $check, string $status, string $message) use (&$results): void {
            $results[] = ['check' => $check, 'status' => $status, 'message' => $message];
        };

        // PHP and its extensions.
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $missing = [];
        foreach (array_keys(is_array($composer['require'] ?? null) ? $composer['require'] : []) as $package) {
            if (str_starts_with((string) $package, 'ext-') && !extension_loaded(substr((string) $package, 4))) {
                $missing[] = substr((string) $package, 4);
            }
        }
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            $add('php', 'error', 'PHP ' . PHP_VERSION . ': TinyTank needs 8.4 or later.');
        } elseif ($missing !== []) {
            $add('php', 'error', 'PHP ' . PHP_VERSION . ', without the extension' . (count($missing) > 1 ? 's ' : ' ') . implode(', ', $missing) . ': install ' . implode(', ', array_map(static fn (string $e) => "php8.4-{$e}", $missing)) . '.');
        } else {
            $add('php', 'ok', 'PHP ' . PHP_VERSION . ', with ' . (function_exists('opcache_get_status') ? 'Opcache' : 'no Opcache (pages are slower without it)') . '.');
        }

        // The environment.
        $secret = (string) getenv('APP_SECRET');
        $url = $app->site->baseUrl;
        if (strlen($secret) < 32 || preg_match('/^(change|your|secret|x+$)/i', $secret)) {
            $add('environment', 'error', 'APP_SECRET must be a random value of 32 characters or more, of this site only: `openssl rand -hex 32`.');
        } elseif (!preg_match('#^https?://[^/\s]+$#', $url)) {
            $add('environment', 'error', "APP_URL must be the site's address, without a trailing slash, e.g. https://example.com (got \"{$url}\").");
        } elseif (str_starts_with($url, 'http://') && !preg_match('#^http://(localhost|127\.|[^/]+\.(test|ddev\.site|local))#', $url)) {
            $add('environment', 'warning', "APP_URL is {$url}: use https:// in production.");
        } else {
            $add('environment', 'ok', "APP_SECRET set, APP_URL {$url}.");
        }

        // The folders the site and deploy write to.
        $unwritable = [];
        foreach (['var', 'var/cache', 'var/state', 'var/log', 'var/images', 'var/deploy', 'public/media'] as $dir) {
            $path = "{$root}/{$dir}";
            while (!file_exists($path) && dirname($path) !== $path) {
                $path = dirname($path); // created on first use: its parent must be writable
            }
            if (!is_writable($path)) {
                $unwritable[] = $dir;
            }
        }
        $unwritable === []
            ? $add('writable', 'ok', 'var/ and public/media/ can be written.')
            : $add('writable', 'error', implode(', ', $unwritable) . ' can\'t be written by ' . (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user') : 'this user') . ': deploy and the site need to.');

        // Content: compiling it checks front matter, components, links between translations…
        $images = 0;
        try {
            [$posts] = $app->blog->warmup();
            [$pages] = $app->pages->warmup();
            $items = array_sum($app->collections->warmup());
            $app->translations->warmup();
            $app->router->warmup();
            foreach ([$app->posts(), $app->pages(), ...array_map(static fn (string $name) => $app->collection($name), array_keys($app->collections->schemas))] as $query) {
                foreach ($query->all() as $item) {
                    $images += count(array_filter((array) ($item['assets'] ?? []), static fn (mixed $file) => is_string($file) && Images::isRaster($file)));
                }
            }
            $add('content', 'ok', "{$posts} posts, {$pages} pages, {$items} collection items, translations and routes compile.");
        } catch (\Throwable $e) {
            $add('content', 'error', $e->getMessage());
        }

        // Images: published without their metadata, which needs an image library.
        $driver = $app->images->driverName();
        if ($driver === null) {
            $add('images', $images > 0 ? 'error' : 'warning', 'No GD or Imagick: ' . ($images > 0 ? "the content's {$images} images can't be published (their metadata can't be removed)" : 'images in content couldn\'t be published') . '. Install php8.4-gd or php8.4-imagick.');
        } else {
            $formats = $app->images->formats;
            $add('images', $formats === [] ? 'warning' : 'ok', ucfirst($driver) . ($formats === [] ? ', without AVIF or WebP: images are served in their own format only.' : ', writing ' . implode(' and ', array_map(static fn (string $format) => ['avif' => 'AVIF', 'webp' => 'WebP'][$format] ?? $format, $formats)) . '.'));
        }

        // Templates: each compiles; each page's template, and its form, exist.
        try {
            $count = DeployCommand::compileTemplates($app, $root);
            $problems = [];
            $loader = $app->twig->getLoader();
            foreach (array_keys($app->site->languages) as $language) {
                foreach ($app->pages()->language($language)->all() as $page) {
                    $template = $page['template'] ?? 'page.twig';
                    if (!$loader->exists($template)) {
                        $problems["{$page['source']}:t"] = "{$page['source']}: its template {$template} doesn't exist.";
                    }
                    if ($page['form'] !== null) {
                        try {
                            $app->forms->get($page['form']);
                        } catch (\InvalidArgumentException) {
                            $problems["{$page['source']}:f"] = "{$page['source']}: the form \"{$page['form']}\" isn't in config/forms.php.";
                        }
                    }
                }
            }
            $problems === []
                ? $add('templates', 'ok', "{$count} templates compile; every page's template and form exist.")
                : $add('templates', 'error', implode(' ', $problems));
        } catch (\Throwable $e) {
            $add('templates', 'error', $e->getMessage());
        }

        // Pages a route answers first.
        try {
            $shadowed = $app->shadowedPages();
            $shadowed === []
                ? $add('pages', 'ok', 'No content page is hidden behind a route.')
                : $add('pages', 'error', implode(' ', array_map(static fn (string $uri, string $route) => "/{$uri} is answered by the route \"{$route}\" first: rename the page's folder or the route.", array_keys($shadowed), $shadowed)));
        } catch (\Throwable $e) {
            $add('pages', 'error', $e->getMessage());
        }

        // Vite: the build, and the entry points templates name.
        $manifest = "{$root}/public/build/.vite/manifest.json";
        if (!is_file($manifest)) {
            glob("{$root}/vite.config.{js,mjs,cjs,ts,mts,cts}", GLOB_BRACE) !== []
                ? $add('vite', 'error', 'No Vite build (public/build/.vite/manifest.json): run `npm run build`; every page needs it.')
                : $add('vite', 'ok', 'No Vite config: pages are served without built assets.');
        } else {
            $entries = json_decode((string) file_get_contents($manifest), true);
            $missing = is_array($entries) ? array_values(array_diff(self::viteEntries($root), array_keys($entries))) : null;
            if ($missing === null) {
                $add('vite', 'error', 'public/build/.vite/manifest.json isn\'t valid JSON: run `npm run build` again.');
            } elseif ($missing !== []) {
                $add('vite', 'error', 'Templates use ' . implode(', ', $missing) . ', not in the Vite build: add ' . (count($missing) > 1 ? 'them' : 'it') . ' to the entry points in vite.config.js and run `npm run build`.');
            } else {
                $add('vite', 'ok', count($entries) . ' entries built, including every one templates name.');
            }
        }

        return $results;
    }

    /**
     * The entry points the site's templates name: in `vite(…)` and `page_scripts = […]`.
     *
     * @return list<string>
     */
    private static function viteEntries(string $root): array
    {
        $entries = [];
        $dir = "{$root}/templates";
        if (!is_dir($dir)) {
            return [];
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            preg_match_all('/\b(?:vite\(|page_scripts\s*=\s*\[)([^)\]]*)/', (string) file_get_contents($file->getPathname()), $calls);
            foreach ($calls[1] as $arguments) {
                preg_match_all('/[\'"]([\w.\/-]+\.(?:js|ts|css|scss))[\'"]/', $arguments, $names);
                array_push($entries, ...$names[1]);
            }
        }

        return array_values(array_unique($entries));
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
