<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use TinyTank\Blog\MarkdownParser;

/** What Markdown renders is usable with a screen reader. */
final class MarkdownAccessibilityTest extends FrameworkTestCase
{
    public function testATaskListsCheckboxesAreNamedAfterTheirItems(): void
    {
        [, $html] = (new MarkdownParser())->convert("- [x] Write the **docs**\n- [ ] Ship `v1` <soon>\n", 'x.md');

        self::assertStringContainsString('<input type="checkbox" disabled="" checked="" aria-label="Write the docs">', $html, 'the item\'s text, as plain text');
        self::assertStringContainsString('<input type="checkbox" disabled="" aria-label="Ship v1 &lt;soon&gt;">', $html, 'escaped');
    }
}
