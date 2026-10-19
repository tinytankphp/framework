<?php

declare(strict_types=1);

namespace TinyTank\Static\Host;

use TinyTank\Static\Build;
use TinyTank\Static\HostAdapter;

/**
 * Any static host, without host files: GitHub Pages, a plain web server. Pages as <path>.html, which
 * most hosts serve without the extension (nginx: `try_files $uri $uri.html $uri/ =404;`). Headers
 * can't be set this way, so the Content Security Policy is lost; redirects become small HTML pages
 * that send the visitor on.
 */
final class NoHost implements HostAdapter
{
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
        $files = [];
        foreach ($build->redirects as $from => $redirect) {
            $to = htmlspecialchars($redirect['to'], ENT_QUOTES | ENT_HTML5);
            $files[$this->file($from)] = "<!doctype html>\n<meta charset=\"utf-8\">\n<title>Moved</title>\n"
                . "<link rel=\"canonical\" href=\"{$to}\">\n<meta http-equiv=\"refresh\" content=\"0; url={$to}\">\n"
                . "<meta name=\"robots\" content=\"noindex\">\n<p>This page moved: <a href=\"{$to}\">{$to}</a>.</p>\n";
        }

        return $files;
    }
}
