<?php

declare(strict_types=1);

namespace TinyTank\Console;

/**
 * A site's starter examples (the skeleton's demos, sample posts and sample translation), as
 * `site:clean` removes them. Two parts, both in the site itself:
 *
 *   .examples                       a list of the files and folders that are examples (one per
 *                                   line, relative to the site; # comments), deleted whole
 *   example:start … example:end     marked blocks inside the site's other files (any comment
 *                                   style: // example:start, {# example:start #}…): both lines and
 *                                   everything between them are deleted
 *
 * After cleaning, .examples is deleted too: its presence is how `deploy` knows the examples are
 * still there.
 */
final class Examples
{
    public const MANIFEST = '.examples';

    /** Where marked blocks are looked for: the site's own code, templates, tests and docs. */
    private const SCANNED = ['config', 'src', 'templates', 'resources', 'tests', 'translations', 'content', 'public'];
    private const SCANNED_ROOT_FILES = ['AGENTS.md', 'README.md', 'package.json', 'composer.json'];
    private const TEXT = ['php', 'twig', 'js', 'mjs', 'ts', 'css', 'md', 'yaml', 'yml', 'json', 'txt', 'neon', 'xml'];

    public function __construct(private readonly string $root)
    {
    }

    /** Whether the site still has its examples (it has a .examples list). */
    public function present(): bool
    {
        return is_file($this->root . '/' . self::MANIFEST);
    }

    /**
     * What cleaning would do.
     *
     * @return array{delete: list<string>, edit: array<string, int>} paths to delete (relative,
     *         existing ones only) and files with marked blocks => how many blocks
     */
    public function plan(): array
    {
        if (!$this->present()) {
            return ['delete' => [], 'edit' => []];
        }
        $delete = [];
        foreach (file($this->root . '/' . self::MANIFEST, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $path = trim($line);
            if ($path === '' || str_starts_with($path, '#')) {
                continue;
            }
            if (str_contains($path, '..') || str_starts_with($path, '/')) {
                throw new \RuntimeException(self::MANIFEST . ": \"{$path}\" must be a path inside the site.");
            }
            foreach (glob($this->root . '/' . $path, GLOB_NOSORT) ?: [] as $match) {
                $delete[] = substr($match, strlen($this->root) + 1);
            }
        }
        $delete = array_values(array_unique($delete));
        sort($delete);

        $edit = [];
        foreach ($this->scannedFiles() as $file) {
            $relative = substr($file, strlen($this->root) + 1);
            if (self::deleted($relative, $delete)) {
                continue;
            }
            $count = self::strip((string) file_get_contents($file), $relative)[1];
            if ($count > 0) {
                $edit[$relative] = $count;
            }
        }
        ksort($edit);

        return ['delete' => $delete, 'edit' => $edit];
    }

    /**
     * Removes the examples: the listed files and folders, the marked blocks, then .examples.
     *
     * @return array{delete: list<string>, edit: array<string, int>} what was done
     */
    public function clean(): array
    {
        $plan = $this->plan(); // checks every file first: a broken marker stops before anything changes
        foreach ($plan['edit'] as $file => $count) {
            $path = $this->root . '/' . $file;
            file_put_contents($path, self::strip((string) file_get_contents($path), $file)[0]);
        }
        foreach ($plan['delete'] as $path) {
            self::remove($this->root . '/' . $path);
        }
        if ($this->present()) {
            unlink($this->root . '/' . self::MANIFEST);
        }

        return $plan;
    }

    /**
     * The text without its marked blocks, and how many there were.
     *
     * @return array{string, int}
     */
    public static function strip(string $text, string $file = 'text'): array
    {
        if (!str_contains($text, 'example:')) {
            return [$text, 0];
        }
        $out = [];
        $open = null;
        $count = 0;
        foreach (preg_split('/(?<=\n)/', $text) ?: [] as $number => $line) {
            if (preg_match('/\bexample:start\b/', $line)) {
                if ($open !== null) {
                    throw new \RuntimeException("{$file}: line " . ($number + 1) . ": example:start inside the block opened on line {$open}.");
                }
                $open = $number + 1;
                continue;
            }
            if (preg_match('/\bexample:end\b/', $line)) {
                if ($open === null) {
                    throw new \RuntimeException("{$file}: line " . ($number + 1) . ': example:end without an example:start.');
                }
                $open = null;
                ++$count;
                continue;
            }
            if ($open === null) {
                $out[] = $line;
            }
        }
        if ($open !== null) {
            throw new \RuntimeException("{$file}: the example:start on line {$open} has no example:end.");
        }

        return [implode('', $out), $count];
    }

    /** @return list<string> absolute paths of the text files that may hold marked blocks */
    private function scannedFiles(): array
    {
        $files = [];
        foreach (self::SCANNED as $dir) {
            if (!is_dir("{$this->root}/{$dir}")) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$this->root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                // Built and published files are copies: they go with the next build or deploy.
                if (preg_match('#/public/(build|media)/#', $path) || !in_array(strtolower($file->getExtension()), self::TEXT, true)) {
                    continue;
                }
                $files[] = $path;
            }
        }
        foreach (self::SCANNED_ROOT_FILES as $file) {
            if (is_file("{$this->root}/{$file}")) {
                $files[] = "{$this->root}/{$file}";
            }
        }
        sort($files);

        return $files;
    }

    /** @param list<string> $deleted */
    private static function deleted(string $file, array $deleted): bool
    {
        foreach ($deleted as $path) {
            if ($file === $path || str_starts_with($file, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
