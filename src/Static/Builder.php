<?php

declare(strict_types=1);

namespace TinyTank\Static;

use Symfony\Component\HttpFoundation\Request;
use TinyTank\Forms\Forms;
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
 * server (template actions, other methods than GET) and forms that post to the site. A Datastar GET
 * to a plain page of the site (`@get('/blog/search')`) is built like a link: the file answers it.
 */
final class Builder
{
    private const START = ['/', '/sitemap.xml', '/robots.txt'];
    private const DATASTAR = '#@(get|post|put|patch|delete)\(&quot;([^&]+(?:&amp;[^&]+)*)&quot;#';

    /** @var array<string, string> URL path => the page it was found on */
    private array $foundOn = [];

    private readonly HostAdapter $host;

    /**
     * @param list<string>         $paths   extra URL paths to render (`static.paths`)
     * @param array<string, mixed> $options the host's settings (`static` in config/app.php)
     */
    public function __construct(
        private readonly Kernel $app,
        private readonly string $out,
        private readonly array $paths = [],
        ?HostAdapter $host = null,
        private readonly array $options = [],
    ) {
        $this->host = $host ?? new Host\Cloudflare();
    }

    public function run(): Build
    {
        $build = new Build($this->out, $this->app->site->baseUrl, $this->app->site->name(), $this->options);
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

        // Each form page's "sent" state, which the host sends visitors to: the page with ?sent=1, as
        // the PHP site shows it after a post without JavaScript.
        foreach ($build->forms as $form) {
            foreach (array_keys($form['pages']) as $page) {
                $this->render(rtrim($page, '/') . '/sent', $build, $page . '?sent=1');
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

    /**
     * @param string|null $source the URL to render instead of $path (the "sent" state: /contact?sent=1)
     *
     * @return list<string> the internal links of what was rendered
     */
    private function render(string $path, Build $build, ?string $source = null): array
    {
        $response = $this->app->handle(Request::create($this->app->site->baseUrl . ($source ?? $path)));
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
        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'X-Frame-Options', 'X-Robots-Tag'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }
        $build->pages[$path] = ['file' => $file, 'type' => $type, 'headers' => $headers];

        if (str_starts_with($type, 'text/html')) {
            $this->forms($body, $path, $build);
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
            if ($method === 'get' && !isset($query['config']) && $this->internal($url) !== null) {
                continue; // a plain page of this site, fetched by Datastar: built like any other (see links())
            }
            $config = json_decode((string) base64_decode(strtr(explode('.', is_string($query['config'] ?? null) ? $query['config'] : '')[0], '-_', '+/')), true);
            $target = is_array($config) && is_string($config['t'] ?? null) ? "template {$config['t']}" : (string) parse_url($url, PHP_URL_PATH);
            $found[] = 'a Datastar ' . strtoupper($method) . " action to the server ({$target})";
        }
        if (preg_match_all('#<form\b[^>]*\bmethod="post"[^>]*>#i', $html, $forms)) {
            foreach ($forms[0] as $form) {
                $action = preg_match('#\baction="([^"]*)"#', $form, $m) ? html_entity_decode($m[1]) : '';
                if ($this->staticForm($action) !== null) {
                    continue; // answered by the host
                }
                if ($this->internal($action === '' ? '/' : $action) !== null) {
                    $found[] = 'a form posting to the site (' . ($action ?: 'this page') . ')';
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** The form a static build's action posts to (/forms/contact → contact), if it's one the host answers. */
    private function staticForm(string $action): ?string
    {
        if (!preg_match('#^' . preg_quote(Forms::STATIC_URL, '#') . '/([a-z][a-z0-9_-]*)$#', $action, $m)) {
            return null;
        }
        $form = $this->app->forms->forms[$m[1]] ?? null;

        return $form?->static !== null ? $m[1] : null;
    }

    /** Records the static forms on a page, with what the host needs to answer them. */
    private function forms(string $html, string $path, Build $build): void
    {
        preg_match_all('#<form\b[^>]*\baction="([^"]*)"#i', $html, $actions);
        foreach ($actions[1] as $action) {
            $name = $this->staticForm(html_entity_decode($action));
            if ($name === null || str_ends_with($path, '/sent')) {
                continue;
            }
            $language = $this->app->site->language();
            $build->forms[$name] ??= $this->formData($name);
            $build->forms[$name]['pages'][$path] = $language;
            foreach (array_keys($this->app->site->languages) as $code) {
                if (!isset($build->forms[$name]['messages'][$code]) && ($code === $language || in_array($code, $build->forms[$name]['pages'], true))) {
                    $build->forms[$name]['messages'][$code] = $this->messages($code);
                    $build->forms[$name]['subject'][$code] = $this->subject($name, $code);
                }
            }
        }
    }

    /** @return array{pages: array<string, string>, fields: array<string, array{type: string, required: bool, min: ?int, max: int, choices: list<string>}>, to: list<string>, from: string, send: string, reply_to: ?string, honeypot: bool, max_links: ?int, subject: array<string, string>, messages: array<string, array<string, string>>} */
    private function formData(string $name): array
    {
        $form = $this->app->forms->get($name);
        $to = $form->static['to'] ?? [];
        if ($to === []) {
            throw new \RuntimeException("Form \"{$name}\" has no recipient: set \"to\" in config/forms.php, or static.to there for the static site.");
        }

        return [
            'pages' => [],
            'fields' => $form->fields,
            'to' => $to,
            'from' => (string) ($form->static['from'] ?? ''),
            'send' => (string) ($form->static['send'] ?? 'cloudflare'),
            'reply_to' => $form->replyTo,
            'honeypot' => $form->honeypot(),
            'max_links' => $form->maxLinks(),
            'subject' => [],
            'messages' => [],
        ];
    }

    /** @return array<string, string> the texts the host shows or checks with, in a language */
    private function messages(string $language): array
    {
        $t = fn (string $text, array $params = []) => $this->app->t($text, $params, $language);

        return [
            'required' => $t('This field is required.'),
            'email' => $t('Enter a valid email address.'),
            'choice' => $t('Choose one of the options.'),
            'min' => $t('Use at least {min} characters.', ['min' => '{min}']),
            'max' => $t('Use at most {max} characters.', ['max' => '{max}']),
            'links' => $t('Please include at most {max} links.', ['max' => '{max}']),
            'not_sent' => $t('Your message wasn\'t sent.'),
            'check' => $t('Please check these and send it again:'),
            'back' => $t('Back to the form'),
            'failed' => $t('Sending didn\'t work just now. Please try again in a few minutes.'),
        ];
    }

    /** The subject in a language, with the fields as placeholders for the host: "Message from {name}". */
    private function subject(string $name, string $language): string
    {
        $form = $this->app->forms->get($name);
        $placeholders = array_combine(array_keys($form->fields), array_map(static fn (string $field) => '{' . $field . '}', array_keys($form->fields)));

        return $this->app->t($form->subject, ['form' => $name, 'site' => $this->app->site->name($language)] + $placeholders, $language);
    }

    /** @return list<string> internal page links to render; missing files are errors */
    private function links(string $body, Build $build, string $from): array
    {
        // Attributes in HTML (srcset too), <loc> in the sitemap, Sitemap: in robots.txt.
        preg_match_all('#(?:href|src|srcset|content)="([^"]+)"|<loc>([^<]+)</loc>|^Sitemap:\s*(\S+)#mi', $body, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        // Pages Datastar fetches with a plain GET (`@get('/blog/search')`): files too, in a static build.
        preg_match_all(self::DATASTAR, $body, $actions, PREG_SET_ORDER);
        foreach ($actions as [, $method, $url]) {
            if ($method === 'get' && !str_contains($url, 'config=')) {
                $matches[] = [null, $url, null, null];
            }
        }
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
