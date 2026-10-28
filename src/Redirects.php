<?php

declare(strict_types=1);

namespace TinyTank;

/**
 * The site's redirect map, config/redirects.php: an old path and where it went, for pages that moved
 * or were renamed. Checked first, before languages and routes, so an old URL keeps working.
 *
 *   return [
 *       '/old-page' => '/new-page',                          // 301, moved for good
 *       '/it/vecchia' => '/it/nuova',                        // each language's path as it is
 *       '/summer-sale' => ['to' => '/offers', 'status' => 302], // temporary
 *       '/docs' => 'https://docs.example.com',               // another site
 *   ];
 *
 * The query string is kept (/old?page=2 → /new?page=2). A static build writes the map into the
 * host's redirects. `bin/console check` reports an old path a page still answers, and a target that
 * doesn't answer.
 */
final class Redirects
{
    public const STATUSES = [301, 302, 307, 308];

    /** @var array<string, array{to: string, status: int}> old path (no trailing slash) => where it went */
    public readonly array $map;

    /** @param array<mixed> $map */
    public function __construct(array $map)
    {
        $redirects = [];
        foreach ($map as $from => $target) {
            $where = "config/redirects.php, \"{$from}\"";
            if (!is_string($from) || !self::isPath($from)) {
                throw new \InvalidArgumentException("{$where}: the old address is a path of this site, starting with / (without ?query or #anchor), e.g. '/old-page' => '/new-page'.");
            }
            $target = is_string($target) ? ['to' => $target] : $target;
            if (!is_array($target) || array_diff(array_keys($target), ['to', 'status']) !== [] || !is_string($target['to'] ?? null)) {
                throw new \InvalidArgumentException("{$where}: give the new address, '/new-page', or ['to' => '/new-page', 'status' => 302].");
            }
            $to = $target['to'];
            if (!self::isPath(strtok($to, '?#') ?: '/') && !preg_match('#^https?://[^/\s]+(/\S*)?$#', $to)) {
                throw new \InvalidArgumentException("{$where}: \"{$to}\" is a path of this site (/new-page) or a full address (https://example.com/page).");
            }
            $status = $target['status'] ?? 301;
            if (!in_array($status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("{$where}: \"status\" is 301 (moved for good, the default), 302 (temporary), 307 or 308.");
            }
            $redirects[self::normalize($from)] = ['to' => $to, 'status' => $status];
        }
        foreach ($redirects as $from => ['to' => $to]) {
            if (self::normalize((string) strtok($to, '?#')) === $from) {
                throw new \InvalidArgumentException("config/redirects.php, \"{$from}\": it redirects to itself.");
            }
            $next = isset($redirects[self::normalize((string) strtok($to, '?#'))]) ? $redirects[self::normalize((string) strtok($to, '?#'))]['to'] : null;
            if (str_starts_with($to, '/') && $next !== null) {
                throw new \InvalidArgumentException("config/redirects.php, \"{$from}\": \"{$to}\" redirects too, to \"{$next}\". Point \"{$from}\" straight at where it ends, so visitors and search engines take one step.");
            }
        }
        $this->map = $redirects;
    }

    /**
     * Where a path went, or null; /old and /old/ are the same.
     *
     * @return array{to: string, status: int}|null
     */
    public function find(string $path): ?array
    {
        return $this->map[self::normalize($path)] ?? null;
    }

    private static function isPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !preg_match('/[?#\s]/', $path);
    }

    private static function normalize(string $path): string
    {
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
