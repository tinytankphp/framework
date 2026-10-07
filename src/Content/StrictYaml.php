<?php

declare(strict_types=1);

namespace Starlite\Content;

use League\CommonMark\Extension\FrontMatter\Data\FrontMatterDataParserInterface;
use League\CommonMark\Extension\FrontMatter\Exception\InvalidFrontMatterException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML for front matter and collection files, with dates checked before YAML reads them: an unquoted
 * 2026-02-30 would otherwise become a timestamp for March 2, and 2026-2-3 one for February 3,
 * silently. Always Symfony YAML, so every server parses content the same way (CommonMark would pick
 * the yaml PECL extension where it's installed).
 */
final class StrictYaml implements FrontMatterDataParserInterface
{
    /**
     * An unquoted value YAML reads as a date: after "key: " or "- ", alone (or with a comment) or
     * followed by a time. "2026-99-99 roadmap" is a string to YAML, so it's left alone.
     */
    private const DATE = '/^\s*(?:-\s+|[^\s#"\'][^:#]*:\s+)(\d{4})-(\d{1,2})-(\d{1,2})(?=\s*(?:#.*)?$|[Tt]\d|\s+\d{1,2}:)/';

    /**
     * @throws InvalidDate      a date that isn't a real day in YYYY-MM-DD form
     * @throws ParseException   invalid YAML
     */
    public static function read(string $yaml): mixed
    {
        foreach (preg_split('/\R/', $yaml) ?: [] as $index => $line) {
            if (preg_match(self::DATE, $line, $m)
                && (strlen($m[2]) !== 2 || strlen($m[3]) !== 2 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
                throw new InvalidDate(sprintf('"%s-%s-%s" (line %d) is not a date: dates are YYYY-MM-DD, and real days.', $m[1], $m[2], $m[3], $index + 1));
            }
        }

        return Yaml::parse($yaml);
    }

    /** For CommonMark's front matter extension. */
    public function parse(string $frontMatter): mixed
    {
        try {
            return self::read($frontMatter);
        } catch (ParseException $e) {
            throw InvalidFrontMatterException::wrap($e);
        }
    }
}
