<?php

declare(strict_types=1);

namespace TinyTank\Collections;

use TinyTank\Blog\MarkdownParser;
use TinyTank\Suggest;

/**
 * One collection's definition from config/collections.php, and the validation of its items.
 *
 *   'faq' => [
 *       'fields' => ['question' => 'string', 'order' => 'int', 'link' => '?url'],
 *       'sort' => 'order',          // a field, '-field' for descending; default: by slug
 *       'fallback' => false,        // true: an untranslated item appears in the default language
 *       'json' => ['question'],     // fields served at /data/faq.json (true: all); default: none
 *   ],
 *
 * Field types; a leading ? makes a field optional (null when missing):
 *   string, int, float, bool, date (YYYY-MM-DD), url (a /path or https:// URL),
 *   markdown (rendered to HTML), list (a list of plain values), array (any YAML structure)
 */
final class Schema
{
    public const TYPES = ['string', 'int', 'float', 'bool', 'date', 'url', 'markdown', 'list', 'array'];

    /** Keys every item has, so fields can't use them. */
    public const RESERVED = ['slug', 'language', 'html', 'components', 'source', 'assets', 'reading_minutes'];

    /** @var array<string, array{string, bool}> field => [type, required] */
    public readonly array $fields;
    public readonly ?string $sortField;
    public readonly bool $sortDescending;
    public readonly bool $fallback;
    /** @var list<string>|null fields exported as JSON; null: no JSON */
    public readonly ?array $json;

    /** @param array<mixed> $config */
    public function __construct(public readonly string $name, array $config)
    {
        $where = "config/collections.php \"{$name}\"";
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("{$where}: collection names use lowercase letters, digits and underscores (usable as collections.{$name} in Twig).");
        }
        if (in_array($name, Collections::RESERVED, true)) {
            throw new \InvalidArgumentException("{$where}: \"{$name}\" is reserved (content/{$name}/ belongs to TinyTank).");
        }
        $unknown = array_diff(array_keys($config), ['fields', 'sort', 'fallback', 'json']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$where}: unknown option \"" . implode('", "', $unknown) . '" (fields, sort, fallback, json).');
        }
        if (!is_array($config['fields'] ?? null) || $config['fields'] === []) {
            throw new \InvalidArgumentException("{$where}: needs \"fields\", e.g. ['title' => 'string'].");
        }

        $fields = [];
        foreach ($config['fields'] as $field => $type) {
            $required = !str_starts_with((string) $type, '?');
            $type = ltrim((string) $type, '?');
            if (is_string($field) && str_starts_with($field, '?')) {
                $bare = substr($field, 1);
                throw new \InvalidArgumentException("{$where}: field \"{$field}\": an optional field puts the ? on its type, '{$bare}' => '?{$type}'.");
            }
            if (!is_string($field) || !preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
                $suggestion = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $field)), '_'));
                throw new \InvalidArgumentException("{$where}: field \"{$field}\": names use lowercase letters, digits and underscores"
                    . (preg_match('/^[a-z][a-z0-9_]*$/', $suggestion) ? ", e.g. \"{$suggestion}\"." : '.'));
            }
            if (in_array($field, self::RESERVED, true)) {
                throw new \InvalidArgumentException("{$where}: \"{$field}\" is set by TinyTank and can't be a field (" . implode(', ', self::RESERVED) . ').');
            }
            if (!in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException("{$where}: field \"{$field}\" has unknown type \"{$type}\" (" . implode(', ', self::TYPES) . ', optional with a leading ?).');
            }
            $fields[$field] = [$type, $required];
        }
        $this->fields = $fields;

        $sort = $config['sort'] ?? null;
        $this->sortDescending = is_string($sort) && str_starts_with($sort, '-');
        $this->sortField = is_string($sort) ? ltrim($sort, '-') : null;
        if ($sort !== null && ($this->sortField === null || !isset($fields[$this->sortField]) || in_array($fields[$this->sortField][0], ['list', 'array', 'markdown'], true))) {
            throw new \InvalidArgumentException("{$where}: \"sort\" must name a plain field, optionally with a leading - for descending order.");
        }

        $this->fallback = (bool) ($config['fallback'] ?? false);

        $json = $config['json'] ?? false;
        if ($json === true) {
            $this->json = array_keys($fields);
        } elseif ($json === false) {
            $this->json = null;
        } elseif (is_array($json) && array_is_list($json) && array_diff($json, array_keys($fields)) === []) {
            $this->json = array_map('strval', $json);
        } else {
            throw new \InvalidArgumentException("{$where}: \"json\" is true, false or a list of its fields.");
        }
    }

    /**
     * Checks and normalises an item's data. A translation ($original set) may omit any field: it
     * keeps the default-language value.
     *
     * @param array<mixed>                $data
     * @param array<string, mixed>|null   $original the default-language item, for a translation
     * @param array{string, string}|null  $folder   an item folder and its files' public URL: a `url`
     *                                              field may then name a file in it (cover.jpg)
     *
     * @return array<string, mixed> field => value, every field present
     */
    public function item(array $data, string $source, MarkdownParser $markdown, ?array $original = null, ?array $folder = null): array
    {
        $unknown = array_diff(array_keys($data), array_keys($this->fields));
        if ($unknown !== []) {
            $hint = array_intersect($unknown, self::RESERVED) !== [] ? ' (slug and language come from the file name)' : '';
            throw new \RuntimeException("{$source}: unknown field \"" . implode('", "', $unknown) . "\"{$hint}." . Suggest::didYouMean($unknown, array_keys($this->fields)) . " Fields of {$this->name}: " . implode(', ', array_keys($this->fields)) . '.');
        }

        $item = [];
        foreach ($this->fields as $field => [$type, $required]) {
            if (!array_key_exists($field, $data)) {
                if ($original !== null) {
                    $item[$field] = $original[$field];
                    continue;
                }
                if ($required) {
                    throw new \RuntimeException("{$source}: missing field \"{$field}\" ({$type}). To make it optional: '{$field}' => '?{$type}' in config/collections.php.");
                }
                $item[$field] = null;
                continue;
            }
            $value = $data[$field];
            if ($value === null && !$required) {
                $item[$field] = null;
                continue;
            }
            $item[$field] = $this->value($type, $value, $field, $source, $markdown, $folder);
        }

        return $item;
    }

    /** @param array{mixed, string, list<mixed>} $converted */
    private static function markdown(array $converted, string $field, string $source): string
    {
        if ($converted[2] !== []) {
            throw new \RuntimeException("{$source}: field \"{$field}\": components (::name) only work in the Markdown body, not in fields.");
        }

        return $converted[1];
    }

    /** @param array{string, string}|null $folder */
    private function value(string $type, mixed $value, string $field, string $source, MarkdownParser $markdown, ?array $folder): mixed
    {
        $invalid = static fn (string $expected) => new \RuntimeException("{$source}: field \"{$field}\" must be {$expected}.");
        if ($type === 'url' && $folder !== null && is_string($value) && !preg_match('#^(/|[a-z][a-z0-9+.-]*:)#i', $value)) {
            return MarkdownParser::folderFile($value, $folder[0], $folder[1], $source, "field \"{$field}\"");
        }

        return match ($type) {
            'string' => is_string($value) && trim($value) !== '' ? trim($value) : throw $invalid('a non-empty string'),
            'int' => is_int($value) ? $value : throw $invalid('a whole number'),
            'float' => is_int($value) || is_float($value) ? (float) $value : throw $invalid('a number'),
            'bool' => is_bool($value) ? $value : throw $invalid('true or false'),
            'date' => MarkdownParser::date($value, $source, $field),
            'url' => is_string($value) && preg_match('#^(/(?!/)\S*|https://\S+)$#i', $value) ? $value : throw $invalid($folder !== null ? 'a file in its folder, a /path or an https:// URL' : 'a /path or an https:// URL'),
            'markdown' => is_string($value) ? self::markdown($markdown->convert($value, "{$source} ({$field})"), $field, $source) : throw $invalid('Markdown text'),
            'list' => is_array($value) && array_is_list($value) && array_filter($value, static fn ($v) => !is_scalar($v)) === []
                ? $value : throw $invalid('a list of plain values, e.g. [a, b]'),
            'array' => is_array($value) ? $value : throw $invalid('a list or a mapping'),
            default => throw new \LogicException("Unknown type {$type}."),
        };
    }
}
