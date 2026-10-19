<?php

declare(strict_types=1);

namespace TinyTank\Static\Host;

use TinyTank\Static\Build;
use TinyTank\Static\HostAdapter;

/**
 * Cloudflare Pages: pages as <path>.html, which Pages serves at the same URL without a trailing slash
 * (/about from about.html, and /about.html or /about/ redirect there), plus `_headers` and
 * `_redirects` (https://developers.cloudflare.com/pages/configuration/headers/ and /redirects/).
 *
 * Headers: one rule for every URL (`/*`) with what most pages get. The Content Security Policy there
 * is the strictest site-wide one that still lets every page work: what pages add to img-src,
 * media-src, frame-src, font-src or style-src (a video player's frame) is allowed everywhere, which
 * is safe, since it can't run code in the page; script-src, connect-src and the rest stay as most
 * pages have them. A page that needs more than that (a csp_allow() for a script) gets a rule of its
 * own, which first detaches the site-wide policy (`! Content-Security-Policy`: rules that match the
 * same URL are merged otherwise). Pages has room for 100 rules; past that, the build fails.
 */
final class CloudflarePages implements HostAdapter
{
    public const MAX_HEADER_RULES = 100;
    public const MAX_REDIRECTS = 2000;
    private const MAX_HEADER_LINE = 2000;
    private const MAX_REDIRECT_LINE = 1000;

    /** What a page may add to these site-wide without risk: they can't run code in the page. */
    private const SAFE_TO_SHARE = ['img-src', 'media-src', 'frame-src', 'font-src', 'style-src', 'style-src-attr'];

    private const CSP = 'Content-Security-Policy';

    public function file(string $path): string
    {
        $path = trim(rawurldecode($path), '/');
        if ($path === '') {
            return 'index.html';
        }

        return str_contains(basename($path), '.') ? $path : $path . '.html';
    }

    public function files(Build $build): array
    {
        return array_filter([
            '_headers' => $this->headers($build),
            '_redirects' => $this->redirects($build),
        ], static fn (string $contents) => $contents !== '');
    }

    private function headers(Build $build): string
    {
        $pages = array_filter($build->pages, static fn (array $page) => $page['headers'] !== []);
        if ($pages === []) {
            return '';
        }

        // The site-wide headers: each one's most common value.
        $site = [];
        foreach (array_unique(array_merge(...array_map(static fn (array $page) => array_keys($page['headers']), array_values($pages)))) as $name) {
            $site[$name] = self::mostCommon(array_values(array_filter(array_map(static fn (array $page) => $page['headers'][$name] ?? null, $pages))));
        }
        $policies = [];
        if (isset($site[self::CSP])) {
            $policies = array_map(static fn (array $page) => self::parse($page['headers'][self::CSP] ?? ''), $pages);
            $site[self::CSP] = self::write(self::shared(array_values($policies), self::parse($site[self::CSP])));
        }

        $rules = ['/*' => $site, '/build/*' => ['Cache-Control' => 'public, max-age=31536000, immutable']];
        foreach ($pages as $path => $page) {
            $own = [];
            foreach ($page['headers'] as $name => $value) {
                $fits = $name === self::CSP
                    ? self::covers(self::parse($site[self::CSP]), $policies[$path])
                    : $value === $site[$name];
                if (!$fits) {
                    $own["! {$name}"] = '';
                    $own[$name] = $value;
                }
            }
            if ($own !== []) {
                $rules[$path] = $own;
            }
        }
        if (count($rules) > self::MAX_HEADER_RULES) {
            throw new \RuntimeException(sprintf(
                'Cloudflare Pages takes %d header rules, this site needs %d: %d pages need headers of their own (%s…). Give them the same policy, e.g. with csp.sources in config/app.php.',
                self::MAX_HEADER_RULES, count($rules), count($rules) - 2, implode(', ', array_slice(array_keys($rules), 2, 3)),
            ));
        }

        $out = "# Written by bin/console build:static: the headers the PHP site sends, for Cloudflare Pages.\n";
        foreach ($rules as $path => $headers) {
            $out .= "\n{$path}\n";
            foreach ($headers as $name => $value) {
                $line = str_starts_with($name, '! ') ? "  {$name}" : "  {$name}: {$value}";
                if (strlen($line) > self::MAX_HEADER_LINE) {
                    throw new \RuntimeException("The {$name} header for {$path} is longer than the " . self::MAX_HEADER_LINE . ' characters Cloudflare Pages allows on a line.');
                }
                $out .= $line . "\n";
            }
        }

        return $out;
    }

    private function redirects(Build $build): string
    {
        if ($build->redirects === []) {
            return '';
        }
        if (count($build->redirects) > self::MAX_REDIRECTS) {
            throw new \RuntimeException(sprintf('Cloudflare Pages takes %d redirects, this site has %d.', self::MAX_REDIRECTS, count($build->redirects)));
        }
        $out = "# Written by bin/console build:static: the PHP site's redirects, for Cloudflare Pages.\n";
        foreach ($build->redirects as $from => $redirect) {
            $status = in_array($redirect['status'], [301, 302, 303, 307, 308], true) ? $redirect['status'] : 302;
            $line = "{$from} {$redirect['to']} {$status}";
            if (strlen($line) > self::MAX_REDIRECT_LINE || preg_match('/\s/', $from . $redirect['to'])) {
                throw new \RuntimeException("The redirect from {$from} can't be written for Cloudflare Pages (too long, or spaces in the URL).");
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    /**
     * The site-wide policy: the base (most pages' policy) with every page's additions to the
     * directives safe to share.
     *
     * @param list<array<string, list<string>>> $policies
     * @param array<string, list<string>>       $base
     *
     * @return array<string, list<string>>
     */
    private static function shared(array $policies, array $base): array
    {
        foreach ($policies as $policy) {
            foreach (self::SAFE_TO_SHARE as $directive) {
                if (isset($policy[$directive])) {
                    $base[$directive] = array_values(array_unique([...($base[$directive] ?? []), ...$policy[$directive]]));
                }
            }
        }

        return $base;
    }

    /**
     * Whether the site-wide policy lets a page do everything its own does: the same strict directives,
     * and at least its sources for the shared ones.
     *
     * @param array<string, list<string>> $site
     * @param array<string, list<string>> $page
     */
    private static function covers(array $site, array $page): bool
    {
        foreach (array_unique([...array_keys($site), ...array_keys($page)]) as $directive) {
            if (in_array($directive, self::SAFE_TO_SHARE, true)) {
                if (array_diff($page[$directive] ?? [], $site[$directive] ?? []) !== []) {
                    return false;
                }
            } elseif (($site[$directive] ?? null) !== ($page[$directive] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, list<string>> directive => sources, in order */
    private static function parse(string $policy): array
    {
        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', $policy))) as $part) {
            $words = preg_split('/\s+/', $part) ?: [];
            $name = strtolower((string) array_shift($words));
            $directives[$name] = $words;
        }

        return $directives;
    }

    /** @param array<string, list<string>> $policy */
    private static function write(array $policy): string
    {
        return implode('; ', array_map(static fn (string $name, array $sources) => trim($name . ' ' . implode(' ', $sources)), array_keys($policy), $policy));
    }

    /** @param list<string> $values */
    private static function mostCommon(array $values): string
    {
        $counts = array_count_values($values);
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
