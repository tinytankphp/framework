<?php

declare(strict_types=1);

/*
 * PHP's built-in server for `bin/console preview:static --php`: serves a static build like a static
 * host, without Node. /about from about.html (or about/index.html), the `_redirects` rules, and
 * 404.html for the rest. The host's headers (`_headers`) and the forms' Worker need Cloudflare's own
 * server, the default preview.
 */

$root = (string) getenv('TINYTANK_STATIC_ROOT');
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($root === '' || str_contains($path, '..')) {
    http_response_code(400);

    return true;
}

// The host's redirects: "from to status", exact paths.
foreach (is_file("{$root}/_redirects") ? file("{$root}/_redirects", FILE_IGNORE_NEW_LINES) ?: [] : [] as $line) {
    $rule = preg_split('/\s+/', trim($line)) ?: [];
    if (count($rule) >= 2 && !str_starts_with($rule[0], '#') && $rule[0] === $path) {
        header('Location: ' . $rule[1], true, (int) ($rule[2] ?? 302));

        return true;
    }
}

$private = ['/_headers', '/_redirects', '/wrangler.jsonc', '/worker.js', '/.assetsignore', '/.tinytank-static'];
if (in_array($path, $private, true)) {
    $path = '/__tinytank-not-found';
}
foreach ([$path, rtrim($path, '/') . '.html', rtrim($path, '/') . '/index.html'] as $candidate) {
    if ($candidate !== '/' && $candidate !== '.html' && is_file($root . $candidate)) {
        if (str_ends_with($candidate, '.html') && $candidate !== $path) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($root . $candidate);

            return true;
        }

        return false; // the built-in server sends the file with its type
    }
}
if ($path === '/' && is_file("{$root}/index.html")) {
    header('Content-Type: text/html; charset=utf-8');
    readfile("{$root}/index.html");

    return true;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
if (is_file("{$root}/404.html")) {
    readfile("{$root}/404.html");
}

return true;
