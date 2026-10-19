<?php

declare(strict_types=1);

namespace TinyTank\Static;

/**
 * How a static host wants the build: where each page's file goes (hosts map URLs to files
 * differently), and the host's own files, such as Cloudflare Pages' `_headers` and `_redirects`, that
 * carry what the PHP site sends with each response: its headers (the Content Security Policy…) and
 * its redirects.
 *
 * Built in: `cloudflare` (Host\Cloudflare: Workers with static assets) and `none` (Host\NoHost). Your own: a class with a
 * constructor without arguments, named in config/app.php (`'static' => ['host' => MyHost::class]`).
 */
interface HostAdapter
{
    /**
     * The file a page goes to, relative to the output: '/' → 'index.html', '/blog' → 'blog.html' or
     * 'blog/index.html', as the host serves it at that same URL. Paths with an extension
     * ('/sitemap.xml') usually stay as they are.
     */
    public function file(string $path): string;

    /**
     * The host's own files, path => contents, from what the build found: every page with the headers
     * the PHP site sends it, every redirect. Throws when the build doesn't fit the host's limits.
     *
     * @return array<string, string>
     */
    public function files(Build $build): array;
}
