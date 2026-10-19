<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use PHPUnit\Framework\TestCase;
use TinyTank\Suggest;

final class SuggestTest extends TestCase
{
    public function testATypoGetsTheClosestName(): void
    {
        $known = ['title', 'summary', 'tags', 'date'];

        self::assertSame(' Did you mean "summary"?', Suggest::didYouMean(['sumary'], $known));
        self::assertSame(' Did you mean "title"?', Suggest::didYouMean(['Title'], $known), 'case aside');
        self::assertSame(' Did you mean "tags" for "tag"?', Suggest::didYouMean(['colour', 'tag'], $known), 'with several names, says which one');
    }

    public function testNoGuessWhenNothingIsClose(): void
    {
        self::assertSame('', Suggest::didYouMean(['colour'], ['title', 'summary']));
        self::assertSame('', Suggest::didYouMean(['ab'], ['xy']), 'two letters off a two-letter word is no typo');
        self::assertSame('', Suggest::didYouMean(['x'], []));
    }
}
