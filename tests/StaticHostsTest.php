<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use PHPUnit\Framework\TestCase;
use TinyTank\Static\Build;
use TinyTank\Static\Host\Cloudflare;
use TinyTank\Static\Host\NoHost;

/** The host adapters: how a static build becomes files a host understands. */
final class StaticHostsTest extends TestCase
{
    private const STRICT = "default-src 'self'; script-src 'self' 'sha256-abc'; img-src 'self' data:; frame-src 'self'";

    /**
     * @param array<string, string>                            $csp       path => policy
     * @param array<string, array{to: string, status: int}>    $redirects
     */
    private static function build(array $csp, array $redirects = []): Build
    {
        $build = new Build('/out', 'https://example.com');
        foreach ($csp as $path => $policy) {
            $build->pages[$path] = ['file' => ltrim($path, '/') . '.html', 'type' => 'text/html', 'headers' => ['Content-Security-Policy' => $policy, 'X-Content-Type-Options' => 'nosniff']];
        }
        $build->redirects = $redirects;

        return $build;
    }

    public function testCloudflareFilesKeepTheUrls(): void
    {
        $host = new Cloudflare();

        self::assertSame(['index.html', 'about.html', 'about/credits.html', 'blog/page/2.html', 'sitemap.xml', 'blog/feed.xml'], array_map($host->file(...), ['/', '/about', '/about/credits', '/blog/page/2', '/sitemap.xml', '/blog/feed.xml']));
    }

    public function testOneSiteWidePolicyWithWhatPagesSafelyAdd(): void
    {
        // A video page adds the player's frame: allowed site-wide, no rule of its own.
        $files = (new Cloudflare())->files(self::build([
            '/' => self::STRICT,
            '/about' => self::STRICT,
            '/blog/video' => str_replace("frame-src 'self'", "frame-src 'self' https://www.youtube-nocookie.com", self::STRICT),
        ]));

        self::assertSame(<<<'TXT'
            # Written by bin/console build:static: the headers the PHP site sends, for Cloudflare.

            /*
              Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-abc'; img-src 'self' data:; frame-src 'self' https://www.youtube-nocookie.com
              X-Content-Type-Options: nosniff

            /build/*
              Cache-Control: public, max-age=31536000, immutable

            TXT, $files['_headers'] ?? null);
        self::assertArrayNotHasKey('_redirects', $files, 'no redirects: no file');
    }

    public function testWranglerPublishesTheFolderAndKeepsItsOwnFilesPrivate(): void
    {
        $build = new Build('/out', 'https://example.com', 'Lab 909!', ['name' => null]);
        $files = (new Cloudflare())->files($build);

        $config = json_decode((string) preg_replace('#^//.*\n#', '', $files['wrangler.jsonc'] ?? ''), true);
        self::assertSame(['name' => 'lab-909', 'compatibility_date' => Cloudflare::COMPATIBILITY_DATE, 'assets' => ['directory' => '.', 'binding' => 'ASSETS', 'html_handling' => 'auto-trailing-slash', 'not_found_handling' => '404-page']], $config, 'no forms: no Worker code, only files');
        self::assertSame(".assetsignore\n.tinytank-static\n.wrangler\nwrangler.jsonc\n", $files['.assetsignore'] ?? null);
        self::assertArrayNotHasKey('worker.js', $files);
        self::assertStringContainsString('"name": "my-site"', (new Cloudflare())->files(new Build('/out', 'https://example.com', 'Lab909', ['name' => 'My Site']))['wrangler.jsonc'], 'static.name wins');
    }

    public function testFormsGetAWorkerAndAnEmailBindingLockedToTheirRecipients(): void
    {
        $build = new Build('/out', 'https://example.com', 'Lab909');
        $build->forms['contact'] = [
            'pages' => ['/contact' => 'en'], 'fields' => ['email' => ['type' => 'email', 'required' => true, 'min' => null, 'max' => 254, 'choices' => []]],
            'to' => ['me@example.com'], 'from' => 'contact@forms.example.com', 'reply_to' => 'email', 'honeypot' => true, 'max_links' => 2,
            'subject' => ['en' => 'Message from {email}'], 'messages' => ['en' => ['required' => 'Required.']],
        ];
        $files = (new Cloudflare())->files($build);

        $config = json_decode((string) preg_replace('#^//.*\n#', '', $files['wrangler.jsonc']), true);
        self::assertSame('worker.js', $config['main'] ?? null);
        self::assertSame([['name' => 'FORMS_EMAIL', 'allowed_destination_addresses' => ['me@example.com']]], $config['send_email'] ?? null);
        self::assertStringContainsString('worker.js', $files['.assetsignore'], 'the code is never served');
        self::assertStringContainsString("import { EmailMessage } from 'cloudflare:email';", $files['worker.js'] ?? '');
        self::assertStringContainsString('"from": "contact@forms.example.com"', $files['worker.js']);
        self::assertStringContainsString('"site": "Lab909"', $files['worker.js']);
        self::assertStringNotContainsString('const FORMS = {};', $files['worker.js'], 'the settings are filled in');
    }

    public function testAPageThatNeedsMoreCodeGetsItsOwnPolicyNotTheWholeSite(): void
    {
        $widened = str_replace("script-src 'self' 'sha256-abc'", "script-src 'self' 'sha256-abc' https://maps.example", self::STRICT);
        $headers = (new Cloudflare())->files(self::build(['/' => self::STRICT, '/about' => self::STRICT, '/map' => $widened]))['_headers'] ?? '';

        self::assertStringContainsString("/*\n  Content-Security-Policy: " . self::STRICT . "\n", $headers, 'script-src stays strict site-wide');
        // Matching rules are merged on Cloudflare Pages: the site-wide policy is detached first.
        self::assertStringContainsString("/map\n  ! Content-Security-Policy\n  Content-Security-Policy: {$widened}\n", $headers);
        self::assertStringNotContainsString('maps.example', explode('/map', $headers)[0]);
    }

    public function testRedirects(): void
    {
        $files = (new Cloudflare())->files(self::build(['/' => self::STRICT], [
            '/blog/page/1' => ['to' => '/blog', 'status' => 301],
            '/it/about' => ['to' => '/it/chi-siamo', 'status' => 302],
        ]));

        self::assertSame("# Written by bin/console build:static: the PHP site's redirects, for Cloudflare.\n/blog/page/1 /blog 301\n/it/about /it/chi-siamo 302\n", $files['_redirects'] ?? null);
    }

    public function testTheHostsLimitsFailTheBuildWithAReason(): void
    {
        $pages = ['/' => self::STRICT];
        for ($i = 0; $i < Cloudflare::MAX_HEADER_RULES; ++$i) {
            $pages["/p{$i}"] = str_replace("script-src 'self'", "script-src 'self' https://s{$i}.example", self::STRICT);
        }

        $this->expectExceptionMessage('Cloudflare takes 100 header rules, this site needs 102: 100 pages need headers of their own (/p0, /p1, /p2…)');
        (new Cloudflare())->files(self::build($pages));
    }

    public function testWithoutAHostRedirectsArePages(): void
    {
        $files = (new NoHost())->files(self::build(['/' => self::STRICT], ['/old' => ['to' => '/new', 'status' => 301]]));

        self::assertSame(['old.html'], array_keys($files), 'no _headers: the host can\'t read them');
        self::assertStringContainsString('<meta http-equiv="refresh" content="0; url=/new">', $files['old.html']);
        self::assertStringContainsString('<link rel="canonical" href="/new">', $files['old.html']);
    }
}
