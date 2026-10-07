<?php

declare(strict_types=1);

namespace Starlite\Tests\Fixtures\Spam;

use Starlite\Forms\Form;
use Starlite\Forms\Spam\SpamCheck;
use Starlite\Forms\Spam\SpamResult;
use Symfony\Component\HttpFoundation\Request;

/** A custom spam check, added in the fixture's config/bootstrap.php as a package would: rejects a word. */
final class WordCheck implements SpamCheck
{
    public function __construct(private readonly string $word)
    {
    }

    public function markup(Form $form): string
    {
        return '<input type="hidden" name="word_check" value="1">';
    }

    public function check(Form $form, Request $request): ?SpamResult
    {
        foreach ($request->request->all() as $value) {
            if (is_string($value) && str_contains(strtolower($value), $this->word)) {
                return new SpamResult("word: {$this->word}");
            }
        }

        return null;
    }
}
