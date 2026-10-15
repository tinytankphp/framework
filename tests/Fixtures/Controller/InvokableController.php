<?php

declare(strict_types=1);

namespace TinyTank\Tests\Fixtures\Controller;

use TinyTank\Controller;

final class InvokableController extends Controller
{
    public function __invoke(): string
    {
        return 'invoked';
    }
}
