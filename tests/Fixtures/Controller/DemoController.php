<?php

declare(strict_types=1);

namespace TinyTank\Tests\Fixtures\Controller;

use TinyTank\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;

final class DemoController extends Controller
{
    public function hello(string $name): string
    {
        return 'hello ' . $name . ' in ' . $this->app->site->language();
    }

    public function data(): JsonResponse
    {
        return $this->json(['ok' => true, 'path' => $this->path('about')]);
    }

    public function service(): string
    {
        return 'service:' . $this->get('greeting')['site'];
    }
}
