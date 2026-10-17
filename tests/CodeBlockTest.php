<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use PHPUnit\Framework\TestCase;
use TinyTank\Blog\MarkdownParser;

final class CodeBlockTest extends TestCase
{
    private static function html(string $markdown): string
    {
        return (new MarkdownParser())->convert($markdown, 'x.md')[1];
    }

    public function testCodeIsHighlightedInTheFencesLanguage(): void
    {
        self::assertSame(
            '<pre data-lang="php"><code class="language-php"><span class="hl-keyword">echo</span> <span class="hl-value">\'Hello\'</span>;' . "\n</code></pre>\n",
            self::html("```php\necho 'Hello';\n```"),
        );
    }

    public function testCommonAliasesWork(): void
    {
        self::assertStringContainsString('<span class="hl-keyword">const</span>', self::html("```js\nconst a = 1;\n```"));
        self::assertStringContainsString('<span class="hl-keyword">if</span>', self::html("```twig\n{% if a %}x{% endif %}\n```"));
        self::assertStringContainsString('data-lang="PHP"><code class="language-PHP"><span class="hl-keyword">echo</span>', self::html("```PHP\necho 1;\n```"));
    }

    public function testCodeStaysTextWhateverItContains(): void
    {
        $html = self::html("```html\n<script>alert(1)</script><img src=x onerror=alert(2)>\n```") . self::html("```php\necho '<script>alert(3)</script>';\n```");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;<span class="hl-keyword">script</span>&gt;', $html);
    }

    public function testUnknownLanguagesAndPlainBlocksAreEscapedText(): void
    {
        self::assertSame("<pre data-lang=\"klingon\"><code class=\"language-klingon\">&lt;b&gt; &amp; it\n</code></pre>\n", self::html("```klingon\n<b> & it\n```"));
        self::assertSame("<pre><code>&lt;b&gt; plain\n</code></pre>\n", self::html("```\n<b> plain\n```"));
        self::assertSame("<pre><code>indented &lt;b&gt;\n</code></pre>\n", self::html("    indented <b>"), 'indented code is left as it was');
    }

    public function testTheLanguageCantBreakOutOfTheAttribute(): void
    {
        $html = self::html("```php\"><script>x</script>\necho 1;\n```");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringStartsWith('<pre data-lang="phpscriptxscript"><code class="language-phpscriptxscript">', $html);
    }
}
