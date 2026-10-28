<?php

declare(strict_types=1);

namespace TinyTank;

/**
 * A request the site can't answer as asked: the kernel turns it into that status's error page
 * (`throw new HttpError(403, 'Not allowed.')`), translated, in debug mode too. Its message is shown to
 * the visitor: keep it short and generic.
 */
final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException("An HttpError is a 4xx or 5xx status (got {$status}).");
        }
        parent::__construct($message);
    }
}
