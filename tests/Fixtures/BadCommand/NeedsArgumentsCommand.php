<?php

declare(strict_types=1);

namespace TinyTank\Tests\Fixtures\BadCommand;

use Symfony\Component\Console\Command\Command;

final class NeedsArgumentsCommand extends Command
{
    public function __construct(string $required)
    {
        parent::__construct('fixture:bad:' . $required);
    }
}
