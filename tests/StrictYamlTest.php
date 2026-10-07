<?php

declare(strict_types=1);

namespace Starlite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Starlite\Blog\MarkdownParser;
use Starlite\Content\InvalidDate;
use Starlite\Content\StrictYaml;

final class StrictYamlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function impossibleDates(): iterable
    {
        yield 'February 30' => ['date: 2026-02-30'];
        yield 'one-digit month and day' => ['date: 2026-2-3'];
        yield 'in a list' => ["dates:\n  - 2026-04-31"];
        yield 'with a time' => ['date: 2026-02-30 10:00'];
        yield 'ISO with a time' => ['date: 2026-02-30T10:00:00Z'];
        yield 'not a leap year' => ['date: 2027-02-29'];
    }

    #[DataProvider('impossibleDates')]
    public function testImpossibleDatesAreRefusedBeforeYamlNormalizesThem(string $yaml): void
    {
        $this->expectException(InvalidDate::class);
        $this->expectExceptionMessage('is not a date: dates are YYYY-MM-DD, and real days.');
        StrictYaml::read($yaml);
    }

    public function testRealDatesAndTextAreUnchanged(): void
    {
        self::assertSame(
            ['a' => 1772236800, 'leap' => 1835395200, 'title' => '2026-99-99 roadmap', 'quoted' => '2026-02-30', 'note' => 'see 2026-02-30'],
            StrictYaml::read("a: 2026-02-28\nleap: 2028-02-29 # leap day\ntitle: 2026-99-99 roadmap\nquoted: \"2026-02-30\"\nnote: see 2026-02-30"),
        );
    }

    public function testQuotedDatesAreCheckedWhereADateIsExpected(): void
    {
        self::assertSame('2028-02-29', MarkdownParser::date('2028-02-29', 'x.md'));
        foreach (['2026-02-30', '2026-2-3', '2026-02-03 ', '26-02-03'] as $value) {
            try {
                MarkdownParser::date($value, 'x.md');
                self::fail("{$value} was accepted");
            } catch (\RuntimeException $e) {
                self::assertSame('x.md: front matter needs a "date" in YYYY-MM-DD format.', $e->getMessage());
            }
        }
    }
}
