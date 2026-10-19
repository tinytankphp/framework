<?php

declare(strict_types=1);

namespace TinyTank\Static;

/**
 * What a static build produced and found: the files it wrote, the headers each page would have been
 * sent with, the redirects, and the problems (broken links, server-only features). Host adapters
 * read it to write their own files (`_headers`, `_redirects`…).
 */
final class Build
{
    /** @var array<string, array{file: string, type: string, headers: array<string, string>}> URL path => what was written */
    public array $pages = [];

    /** @var array<string, array{to: string, status: int}> URL path => where it redirects */
    public array $redirects = [];

    /** @var array<string, list<string>> URL path => problems that make the static site differ from the PHP one */
    public array $dynamic = [];

    /** @var array<string, string> URL path => what's wrong (a broken link, an error) */
    public array $errors = [];

    /** Files copied or published besides the pages (the asset build, media, public/ files). */
    public int $files = 0;

    public function __construct(
        public readonly string $out,
        public readonly string $baseUrl,
    ) {
    }
}
