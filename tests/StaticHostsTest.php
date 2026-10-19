<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use PHPUnit\Framework\TestCase;
use TinyTank\Static\Build;
use TinyTank\Static\Host\CloudflarePages;
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

    public function testCloudflarePagesFilesKeepTheUrls(): void
    {
        $host = new CloudflarePages();

        self::assertSame(['index.html', 'about.html', 'about/credits.html', 'blog/page/2.html', 'sitemap.xml', 'blog/feed.xml'], array_map($host->file(...), ['/', '/about', '/about/credits', '/blog/page/2', '/sitemap.xml', '/blog/feed.xml']));
    }

    public function testOneSiteWidePolicyWithWhatPagesSafelyAdd(): void
    {
        // A video page adds the player's frame: allowed site-wide, no rule of its own.
        $files = (new CloudflarePages())->files(self::build([
            '/' => self::STRICT,
            '/about' => self::STRICT,
            '/blog/video' => str_replace("frame-src 'self'", "frame-src 'self' https://www.youtube-nocookie.com", self::STRICT),
        ]));

        self::assertSame(<<<'TXT'
            # Written by bin/console build:static: the headers the PHP site sends, for Cloudflare Pages.

            /*
              Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-abc'; img-src 'self' data:; frame-src 'self' https://www.youtube-nocookie.com
              X-Content-Type-Options: nosniff

            /build/*
              Cache-Control: public, max-age=31536000, immutable

            TXT, $files['_headers'] ?? null);
        self::assertArrayNotHasKey('_redirects', $files, 'no redirects: no file');
    }

    public function testAPageThatNeedsMoreCodeGetsItsOwnPolicyNotTheWholeSite(): void
    {
        $widened = str_replace("script-src 'self' 'sha256-abc'", "script-src 'self' 'sha256-abc' https://maps.example", self::STRICT);
        $headers = (new CloudflarePages())->files(self::build(['/' => self::STRICT, '/about' => self::STRICT, '/map' => $widened]))['_headers'] ?? '';

        self::assertStringContainsString("/*\n  Content-Security-Policy: " . self::STRICT . "\n", $headers, 'script-src stays strict site-wide');
        // Matching rules are merged on Cloudflare Pages: the site-wide policy is detached first.
        self::assertStringContainsString("/map\n  ! Content-Security-Policy\n  Content-Security-Policy: {$widened}\n", $headers);
        self::assertStringNotContainsString('maps.example', explode('/map', $headers)[0]);
    }

    public function testRedirects(): void
    {
        $files = (new CloudflarePages())->files(self::build(['/' => self::STRICT], [
            '/blog/page/1' => ['to' => '/blog', 'status' => 301],
            '/it/about' => ['to' => '/it/chi-siamo', 'status' => 302],
        ]));

        self::assertSame("# Written by bin/console build:static: the PHP site's redirects, for Cloudflare Pages.\n/blog/page/1 /blog 301\n/it/about /it/chi-siamo 302\n", $files['_redirects'] ?? null);
    }

    public function testTheHostsLimitsFailTheBuildWithAReason(): void
    {
        $pages = ['/' => self::STRICT];
        for ($i = 0; $i < CloudflarePages::MAX_HEADER_RULES; ++$i) {
            $pages["/p{$i}"] = str_replace("script-src 'self'", "script-src 'self' https://s{$i}.example", self::STRICT);
        }

        $this->expectExceptionMessage('Cloudflare Pages takes 100 header rules, this site needs 102: 100 pages need headers of their own (/p0, /p1, /p2…)');
        (new CloudflarePages())->files(self::build($pages));
    }

    public function testWithoutAHostRedirectsArePages(): void
    {
        $files = (new NoHost())->files(self::build(['/' => self::STRICT], ['/old' => ['to' => '/new', 'status' => 301]]));

        self::assertSame(['old.html'], array_keys($files), 'no _headers: the host can\'t read them');
        self::assertStringContainsString('<meta http-equiv="refresh" content="0; url=/new">', $files['old.html']);
        self::assertStringContainsString('<link rel="canonical" href="/new">', $files['old.html']);
    }
}
