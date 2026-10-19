<?php

declare(strict_types=1);

namespace TinyTank\Collections;

use TinyTank\Content\AssetResponse;
use TinyTank\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an item folder's files (/media/collections/<collection>/<slug>/<file>) from its content
 * folder, images as published (see AssetResponse): the development path, as `deploy` copies them to
 * public/media/collections/.
 */
final class AssetController extends Controller
{
    public function __invoke(string $file): Response
    {
        return AssetResponse::for($this->app, $file, fn (string $file) => $this->app->collections->asset($file));
    }
}
