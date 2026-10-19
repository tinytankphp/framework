<?php

declare(strict_types=1);

namespace TinyTank\Collections;

use TinyTank\Blog\Blog;
use TinyTank\Blog\MarkdownParser;
use TinyTank\Cache;
use TinyTank\Content\InvalidDate;
use TinyTank\Content\StrictYaml;
use TinyTank\Images\Images;
use TinyTank\Query;
use TinyTank\Site;

/**
 * Data collections: structured content beyond blog posts (FAQs, team, products, a sound catalogue…),
 * defined in config/collections.php (see Schema) and kept in content/<collection>/:
 *
 *   content/faq/what-is-tinytank.md       front matter (the fields) + an optional Markdown body (`html`)
 *   content/faq/what-is-tinytank.it.md    Italian version: omitted fields keep the default-language value
 *   content/team/ada.yaml                 data only
 *   content/projects/pulse-os/index.md    an item as a folder, with its files next to it:
 *   content/projects/pulse-os/cover.jpg   /media/collections/projects/pulse-os/cover.jpg
 *
 * The file (or folder) name is the item's slug. In a folder, relative links and images in the body
 * point at its files (images get responsive versions, as in posts), and a `url` field may name one
 * (`image: cover.jpg`), so `image(item.image)` is responsive too. Query them with `collection('faq')` in Twig or
 * `$app->collection('faq')` in PHP (see TinyTank\Query), in the current language.
 *
 * Without APP_DEBUG every collection is compiled once into var/cache/collections.php (Markdown
 * already rendered), like the blog: run `bin/console deploy` after changing content.
 */
final class Collections
{
    /** content/ folders that belong to TinyTank, not to collections. */
    public const RESERVED = ['blog', 'pages'];

    /** Public URL prefix of item folders' files; `deploy` copies them to public/media/collections/<collection>/<slug>/. */
    public const ASSET_URL = '/media/collections';

    private const SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** @var array<string, Schema> */
    public readonly array $schemas;

    /** @var array<string, array<string, array<string, array<string, mixed>>>>|null language => collection => slug => item */
    private ?array $items = null;

    /** @param array<string, array<mixed>> $config config/collections.php */
    public function __construct(
        array $config,
        private readonly string $contentDir,
        private readonly string $cacheFile,
        private readonly bool $debug,
        private readonly Site $site,
        /** @var (\Closure(string, array<string, string|int|float|bool>): ?string)|null checks a component (see MarkdownParser) */
        private readonly ?\Closure $componentCheck = null,
        /** Responsive versions of images in item folders, and originals without metadata */
        private readonly ?Images $images = null,
        /** Base URL of the files' public URLs: '' (this site) or a CDN, MEDIA_URL */
        private readonly string $mediaUrl = '',
    ) {
        $schemas = [];
        foreach ($config as $name => $definition) {
            $schemas[(string) $name] = new Schema((string) $name, (array) $definition);
        }
        $this->schemas = $schemas;
    }

    /**
     * A query over one collection: `collection('faq').where('featured', true).all()`. Items are in
     * the collection's `sort` order unless the query sets one.
     *
     * @return Query<array<string, mixed>>
     */
    public function query(string $name): Query
    {
        $schema = $this->schema($name);
        $plain = array_keys(array_filter($schema->fields, static fn (array $field) => in_array($field[0], ['string', 'list'], true)));

        return new Query(
            "collection \"{$name}\"",
            fn (string $language) => $this->compiled()[$language][$name] ?? [],
            ['slug', 'language', ...array_keys($schema->fields), 'reading_minutes'],
            $plain,
            $this->site,
        );
    }

    /**
     * The fields allowlisted under `json` in config/collections.php, plus slug and language: what
     * /data/<collection>.json serves to JavaScript.
     *
     * @return list<array<string, mixed>>
     */
    public function json(string $name, ?string $language = null): array
    {
        $schema = $this->schema($name);
        $fields = $schema->json ?? throw new \LogicException("Collection \"{$name}\" has no JSON export: set \"json\" in config/collections.php.");
        $keep = array_flip(['slug', 'language', ...$fields]);
        $items = $this->compiled()[$language ?? $this->site->language()][$name] ?? [];

        return array_values(array_map(static fn (array $item) => array_intersect_key($item, $keep), $items));
    }

    public function schema(string $name): Schema
    {
        return $this->schemas[$name] ?? throw new \InvalidArgumentException(
            "Unknown collection \"{$name}\". Collections: " . (implode(', ', array_keys($this->schemas)) ?: 'none') . ' (config/collections.php).',
        );
    }

    /**
     * Absolute path of an item folder's file ("projects/pulse-os/cover.jpg"), or null if there's no
     * such item or file.
     */
    public function asset(string $file): ?string
    {
        $parts = explode('/', $file, 3);
        if (count($parts) !== 3 || !isset($this->schemas[$parts[0]])) {
            return null;
        }
        [$name, $slug, $path] = $parts;
        foreach ($this->compiled() as $collections) {
            $item = $collections[$name][$slug] ?? null;
            if ($item !== null) {
                return in_array($path, $item['assets'], true) ? "{$this->contentDir}/{$name}/{$slug}/{$path}" : null;
            }
        }

        return null;
    }

    /** Copies item folders' files to public/media/collections/<collection>/<slug>/. Returns the number of files. */
    public function publishAssets(string $publicDir): int
    {
        $target = $publicDir . self::ASSET_URL;
        Cache::clear($target);
        $count = 0;
        $done = [];
        foreach ($this->compiled() as $collections) {
            foreach ($collections as $name => $items) {
                foreach ($items as $slug => $item) {
                    if (isset($done["{$name}/{$slug}"])) {
                        continue;
                    }
                    $done["{$name}/{$slug}"] = true;
                    $dir = "{$target}/{$name}/{$slug}";
                    foreach ($item['assets'] as $file) {
                        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                            throw new \RuntimeException("Cannot create {$dir}.");
                        }
                        $source = "{$this->contentDir}/{$name}/{$slug}/{$file}";
                        if ($this->images !== null && Images::isRaster($file)) {
                            $count += $this->images->publish($source, $dir); // without metadata, plus its smaller versions
                        } else {
                            copy($source, "{$dir}/{$file}");
                            ++$count;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /** @return list<string> collections with a JSON export */
    public function exported(): array
    {
        return array_keys(array_filter($this->schemas, static fn (Schema $schema) => $schema->json !== null));
    }

    /**
     * Parses every collection and writes the cache.
     *
     * @return array<string, int> collection => items (in the default language)
     */
    public function warmup(): array
    {
        $this->items = null;
        $items = $this->compile();
        Cache::writeData($this->cacheFile, $items);
        $this->items = $items;

        return array_map('count', $items[$this->site->defaultLanguage] ?? []);
    }

    /** @return array<string, array<string, array<string, array<string, mixed>>>> */
    private function compiled(): array
    {
        return $this->items ??= $this->debug ? $this->compile() : Cache::remember($this->cacheFile, $this->compile(...));
    }

    /** @return array<string, array<string, array<string, array<string, mixed>>>> language => collection => slug => item, sorted */
    private function compile(): array
    {
        $this->checkContentFolders();
        $markdown = new MarkdownParser($this->componentCheck, $this->images);
        $default = $this->site->defaultLanguage;
        $compiled = array_fill_keys(array_keys($this->site->languages), []);

        foreach ($this->schemas as $name => $schema) {
            $versions = []; // slug => language => item
            foreach ($this->files($name) as $slug => $files) {
                if (!isset($files[$default])) {
                    $other = $files[array_key_first($files)];
                    throw new \RuntimeException("{$name}/{$other}: a translation needs the default-language file {$name}/{$slug}." . pathinfo($other, PATHINFO_EXTENSION) . ' first.');
                }
                $original = null;
                $inFolder = array_filter($files, static fn (string $file) => str_contains($file, '/'));
                if ($inFolder !== [] && count($inFolder) !== count($files)) {
                    throw new \RuntimeException("{$name}/{$slug}: keep every language version of an item in one place, all in its folder or all as files.");
                }
                // An item folder: its files, and where they're served from.
                $folder = str_contains($files[$default], '/')
                    ? ["{$this->contentDir}/{$name}/{$slug}", $this->mediaUrl . self::ASSET_URL . "/{$name}/{$slug}"]
                    : null;
                $assets = $folder !== null ? $this->assets($folder[0], "{$name}/{$slug}") : [];
                foreach ($files as $language => $file) {
                    $source = "{$name}/{$file}";
                    [$data, $html, $components] = $this->read("{$this->contentDir}/{$source}", $source, $markdown, $folder[1] ?? null);
                    $inherit = $html === '' && $original !== null; // a translation without a body keeps the default one
                    $item = ['slug' => $slug, 'language' => $language]
                        + $schema->item($data, $source, $markdown, $original, $folder)
                        + ['html' => $inherit ? $original['html'] : $html, 'components' => $inherit ? $original['components'] : $components, 'source' => $source, 'assets' => $assets];
                    $item['reading_minutes'] = MarkdownParser::readingMinutes($item['html']);
                    $versions[$slug][$language] = $item;
                    $original ??= $item;
                }
            }
            foreach (array_keys($this->site->languages) as $language) {
                $items = [];
                foreach ($versions as $slug => $byLanguage) {
                    $item = $byLanguage[$language] ?? ($schema->fallback ? $byLanguage[$default] : null);
                    if ($item !== null) {
                        $items[$slug] = $item;
                    }
                }
                $compiled[$language][$name] = self::sort($items, $schema);
            }
        }

        return $compiled;
    }

    /**
     * An item's files, default language first: slug => language => file name.
     *
     * @return array<string, array<string, string>>
     */
    private function files(string $name): array
    {
        $dir = "{$this->contentDir}/{$name}";
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (self::entries($dir) as $entry) {
            if (is_dir("{$dir}/{$entry}")) {
                $this->folderFiles($name, $entry, $files);
                continue;
            }
            $filename = $entry;
            if (!preg_match('/^(' . self::SLUG . ')(?:\.([a-z]{2}(?:-[a-z]{2})?))?\.(md|ya?ml)$/', $filename, $m)) {
                throw new \RuntimeException(
                    "{$name}/{$filename}: collections hold <slug>.md or <slug>.yaml files (translations: <slug>.<language>.md), "
                    . 'with a slug of lowercase letters, digits and dashes; an item with files of its own is a folder, <slug>/index.md and its files.',
                );
            }
            [, $slug, $code] = $m;
            if ($code === $this->site->defaultLanguage) {
                throw new \RuntimeException("{$name}/{$filename}: the default language ({$code}) has no language code: {$slug}.{$m[3]}.");
            }
            $language = $code === '' ? $this->site->defaultLanguage : $code;
            if (!isset($this->site->languages[$language])) {
                throw new \RuntimeException("{$name}/{$filename}: language \"{$code}\" is not configured in config/app.php.");
            }
            if (isset($files[$slug][$language])) {
                throw new \RuntimeException("{$name}/{$filename}: \"{$slug}\" already has a file for this language ({$files[$slug][$language]}).");
            }
            $files[$slug][$language] = $filename;
        }
        ksort($files);
        $order = array_flip(array_keys($this->site->languages));
        $order[$this->site->defaultLanguage] = -1;
        foreach ($files as &$languages) {
            uksort($languages, static fn (string $a, string $b) => $order[$a] <=> $order[$b]);
        }
        unset($languages);

        return $files;
    }

    /**
     * An item folder's index files, added to $files (slug => language => "slug/index.md"). It holds
     * index.md or index.yaml (translations: index.<language>.md) and its files, no subfolders.
     *
     * @param array<string, array<string, string>> $files
     */
    private function folderFiles(string $name, string $slug, array &$files): void
    {
        $dir = "{$this->contentDir}/{$name}/{$slug}";
        if (!preg_match('/^' . self::SLUG . '$/', $slug)) {
            throw new \RuntimeException("{$name}/{$slug}/: an item folder's name is its slug: lowercase letters, digits and dashes.");
        }
        foreach (self::entries($dir) as $entry) {
            if (is_dir("{$dir}/{$entry}")) {
                throw new \RuntimeException("{$name}/{$slug}/{$entry}/: an item folder holds its index file and files, no subfolders.");
            }
            if (!preg_match('/^index(?:\.([a-z]{2}(?:-[a-z]{2})?))?\.(md|ya?ml)$/', $entry, $m)) {
                continue; // the item's files (see assets())
            }
            $code = $m[1];
            if ($code === $this->site->defaultLanguage) {
                throw new \RuntimeException("{$name}/{$slug}/{$entry}: the default language ({$code}) has no language code: index.{$m[2]}.");
            }
            $language = $code === '' ? $this->site->defaultLanguage : $code;
            if (!isset($this->site->languages[$language])) {
                throw new \RuntimeException("{$name}/{$slug}/{$entry}: language \"{$code}\" is not configured in config/app.php.");
            }
            if (isset($files[$slug][$language])) {
                throw new \RuntimeException("{$name}/{$slug}/{$entry}: \"{$slug}\" already has a file for this language ({$files[$slug][$language]}).");
            }
            $files[$slug][$language] = "{$slug}/{$entry}";
        }
        if (!isset($files[$slug])) {
            throw new \RuntimeException("{$name}/{$slug}/: an item folder needs an index.md (or index.yaml), with its files next to it.");
        }
    }

    /** @return list<string> publishable files in an item folder */
    private function assets(string $dir, string $label): array
    {
        $assets = [];
        foreach (self::entries($dir) as $name) {
            if (preg_match('/^index(\.[a-z-]+)?\.(md|ya?ml)$/', $name) || !isset(Blog::ASSET_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $name)) {
                throw new \RuntimeException("{$label}/{$name}: file names may only use letters, digits, dots, dashes and underscores.");
            }
            $assets[] = $name;
        }
        sort($assets);

        return $assets;
    }

    /**
     * @param string|null $assetUrl an item folder's public URL: relative links and images in the body
     *                              point at its files; null for a single-file item
     *
     * @return array{array<mixed>, string, list<array<string, mixed>>} fields, HTML of the Markdown body ('' for YAML files), its components
     */
    private function read(string $path, string $source, MarkdownParser $markdown, ?string $assetUrl = null): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }
        try {
            if (str_ends_with($path, '.md')) {
                [$data, $html, $components] = $assetUrl !== null ? $markdown->convertFile($path, $assetUrl, $source) : $markdown->convert($contents, $source);
                $data ??= [];
            } else {
                [$data, $html, $components] = [StrictYaml::read($contents), '', []];
            }
        } catch (\Symfony\Component\Yaml\Exception\ParseException | \League\CommonMark\Exception\CommonMarkException $e) {
            throw new \RuntimeException("{$source}: invalid YAML: {$e->getMessage()}", 0, $e);
        } catch (InvalidDate $e) {
            throw new \RuntimeException("{$source}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \RuntimeException("{$source}: the fields must be a YAML mapping (field: value).");
        }

        return [$data, trim($html), $components];
    }

    /** Every folder in content/ is the blog, pages or a collection: a stray one is likely a typo. */
    private function checkContentFolders(): void
    {
        if (!is_dir($this->contentDir)) {
            return;
        }
        foreach (self::entries($this->contentDir) as $name) {
            if (is_dir("{$this->contentDir}/{$name}") && !in_array($name, self::RESERVED, true) && !isset($this->schemas[$name])) {
                throw new \RuntimeException("content/{$name}/ is not a collection: define it in config/collections.php (collections: " . (implode(', ', array_keys($this->schemas)) ?: 'none') . ').');
            }
        }
    }

    /** @return list<string> names in a folder, without dot files (.gitkeep, .DS_Store…) */
    private static function entries(string $dir): array
    {
        return array_values(array_filter(scandir($dir) ?: [], static fn (string $name) => !str_starts_with($name, '.')));
    }

    /**
     * @param array<string, array<string, mixed>> $items
     *
     * @return array<string, array<string, mixed>>
     */
    private static function sort(array $items, Schema $schema): array
    {
        $field = $schema->sortField;
        if ($field !== null) {
            uasort($items, static function (array $a, array $b) use ($field, $schema): int {
                // Missing values last, whatever the direction; ties by slug.
                if (($a[$field] === null) !== ($b[$field] === null)) {
                    return $a[$field] === null ? 1 : -1;
                }
                $order = $schema->sortDescending ? $b[$field] <=> $a[$field] : $a[$field] <=> $b[$field];

                return $order !== 0 ? $order : $a['slug'] <=> $b['slug'];
            });
        }

        return $items;
    }
}
