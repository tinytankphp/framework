<?php

declare(strict_types=1);

namespace TinyTank\Static;

use Symfony\Component\HttpFoundation\Request;
use TinyTank\Kernel;

/**
 * Renders the whole site into a folder of plain files (`bin/console build:static`).
 *
 * Every URL is found by following links: from the home page, the sitemap, the feed and robots.txt
 * (plus `static.paths` in config/app.php for pages nothing links to), each page is rendered through
 * the kernel, in production mode, and written where the host serves it at the same URL (the host
 * adapter decides: <path>.html for Cloudflare Pages; files with an extension, like /sitemap.xml, as
 * they are). Redirects and each page's headers are recorded; the adapter turns them into the host's
 * own files (`_headers`, `_redirects`). Then public/ is copied (the asset build, the favicon…) and the media of posts, pages
 * and collections published next to the pages.
 *
 * What only a server can do is reported by page, in Build::$dynamic: Datastar actions that call the
 * server (live search, template actions) and forms that post to the site.
 */
final class Builder
{
    private const START = ['/', '/sitemap.xml', '/robots.txt'];
    private const DATASTAR = '#@(get|post|put|patch|delete)\(&quot;([^&]+(?:&amp;[^&]+)*)&quot;#';

    /** @var array<string, string> URL path => the page it was found on */
    private array $foundOn = [];

    private readonly HostAdapter $host;

    /** @param list<string> $paths extra URL paths to render (`static.paths`) */
    public function __construct(
        private readonly Kernel $app,
        private readonly string $out,
        private readonly array $paths = [],
        ?HostAdapter $host = null,
    ) {
        $this->host = $host ?? new Host\CloudflarePages();
    }

    public function run(): Build
    {
        $build = new Build($this->out, $this->app->site->baseUrl);
        $this->app->site->markStatic();

        // Files first, so links to them are checked against what's really there.
        $public = $this->app->root . '/public';
        $build->files += $this->copyPublic($public, $this->out);
        $build->files += $this->app->blog->publishAssets($this->out);
        $build->files += $this->app->pages->publishAssets($this->out);
        $build->files += $this->app->collections->publishAssets($this->out);

        $queue = [];
        foreach ([...self::START, $this->app->path('blog_feed'), ...$this->paths] as $path) {
            $queue[$path] = true;
            $this->foundOn[$path] ??= 'the start';
        }
        $done = [];
        while ($queue !== []) {
            $path = (string) array_key_first($queue);
            unset($queue[$path]);
            if (isset($done[$path])) {
                continue;
            }
            $done[$path] = true;
            foreach ($this->render($path, $build) as $link) {
                if (!isset($done[$link])) {
                    $queue[$link] = true;
                    $this->foundOn[$link] ??= $path;
                }
            }
        }

        // The error page, for the host to serve on unknown URLs.
        $missing = $this->app->handle(Request::create($this->app->site->baseUrl . '/__tinytank-static-404'));
        $this->write('404.html', (string) $missing->getContent());

        // The host's own files: its headers and redirects (they may throw: past the host's limits).
        foreach ($this->host->files($build) as $file => $contents) {
            $this->write($file, $contents);
        }

        return $build;
    }

    /** @return list<string> the internal links of what was rendered */
    private function render(string $path, Build $build): array
    {
        $response = $this->app->handle(Request::create($this->app->site->baseUrl . $path));
        $status = $response->getStatusCode();
        if ($response->isRedirection()) {
            $to = $this->internal((string) $response->headers->get('Location'));
            if ($to === null) {
                $build->errors[$path] = "redirects off the site ({$response->headers->get('Location')})";

                return [];
            }
            $build->redirects[$path] = ['to' => $to, 'status' => $status];

            return [$to];
        }
        if ($status !== 200) {
            $build->errors[$path] = "answers {$status} (linked from {$this->foundOn[$path]})";

            return [];
        }

        $body = (string) $response->getContent();
        $type = (string) $response->headers->get('Content-Type', 'text/html');
        $file = $this->host->file($path);
        $this->write($file, $body);
        $headers = [];
        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'X-Frame-Options'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }
        $build->pages[$path] = ['file' => $file, 'type' => $type, 'headers' => $headers];

        if (str_starts_with($type, 'text/html')) {
            $dynamic = $this->dynamic($body);
            if ($dynamic !== []) {
                $build->dynamic[$path] = $dynamic;
            }
        }

        return $this->links($body, $build, $path);
    }

    /** @return list<string> what in a page needs the server */
    private function dynamic(string $html): array
    {
        $found = [];
        preg_match_all(self::DATASTAR, $html, $actions, PREG_SET_ORDER);
        foreach ($actions as [, $method, $url]) {
            $url = html_entity_decode($url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $config = json_decode((string) base64_decode(strtr(explode('.', is_string($query['config'] ?? null) ? $query['config'] : '')[0], '-_', '+/')), true);
            $target = is_array($config) && is_string($config['t'] ?? null) ? "template {$config['t']}" : (string) parse_url($url, PHP_URL_PATH);
            $found[] = 'a Datastar ' . strtoupper($method) . " action to the server ({$target})";
        }
        if (preg_match_all('#<form\b[^>]*\bmethod="post"[^>]*>#i', $html, $forms)) {
            foreach ($forms[0] as $form) {
                $action = preg_match('#\baction="([^"]*)"#', $form, $m) ? html_entity_decode($m[1]) : '';
                if ($this->internal($action === '' ? '/' : $action) !== null) {
                    $found[] = 'a form posting to the site (' . ($action ?: 'this page') . ')';
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> internal page links to render; missing files are errors */
    private function links(string $body, Build $build, string $from): array
    {
        // Attributes in HTML (srcset too), <loc> in the sitemap, Sitemap: in robots.txt.
        preg_match_all('#(?:href|src|srcset|content)="([^"]+)"|<loc>([^<]+)</loc>|^Sitemap:\s*(\S+)#mi', $body, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $links = [];
        foreach ($matches as $match) {
            $value = html_entity_decode((string) ($match[1] ?? $match[2] ?? $match[3] ?? ''));
            // srcset: "a.jpg 480w, b.jpg 960w"
            foreach (str_contains($value, ', ') || preg_match('/\s\d+[wx]$/', $value) ? array_map(static fn (string $part) => explode(' ', trim($part))[0], explode(',', $value)) : [$value] as $url) {
                $path = $this->internal($url);
                if ($path === null || str_contains($path, '*')) {
                    continue;
                }
                if (is_file($this->out . $path) || is_file($this->out . rawurldecode($path))) {
                    continue; // a copied or published file
                }
                if (str_starts_with($path, '/build/') || str_starts_with($path, '/media/')) {
                    $build->errors[$path] = "is missing (linked from {$from})";

                    continue;
                }
                $links[] = $path;
            }
        }

        return array_values(array_unique($links));
    }

    /** The path of a URL on this site ("/blog/a-post"), without query or fragment; null for others. */
    private function internal(string $url): ?string
    {
        $base = $this->app->site->baseUrl;
        if (str_starts_with($url, $base . '/') || $url === $base) {
            $url = substr($url, strlen($base)) ?: '/';
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $path = (string) preg_replace('/[?#].*$/', '', $url);

        return $path === '' ? '/' : $path;
    }

    private function write(string $file, string $contents): void
    {
        $target = $this->out . '/' . $file;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException("Cannot create " . dirname($target) . '.');
        }
        file_put_contents($target, $contents);
    }

    /** Everything in public/ but PHP and the published media (published fresh). Returns the number of files. */
    private function copyPublic(string $from, string $to): int
    {
        $count = 0;
        if (!is_dir($from)) {
            return 0;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($from) + 1);
            if (preg_match('#^(media/(blog|pages|collections)(/|$))#', $relative) || str_ends_with($relative, '.php') || str_starts_with(basename($relative), '.')) {
                continue;
            }
            $target = "{$to}/{$relative}";
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($file->getPathname(), $target);
            ++$count;
        }

        return $count;
    }
}
