<?php

declare(strict_types=1);

use Starlite\Kernel;
use Symfony\Component\Console\Style\SymfonyStyle;

return static function (Kernel $app): void {
    $app->container->set('greeting', static fn (Kernel $app) => new ArrayObject(['site' => $app->site->name()]));
    $app->twig->addGlobal('fixture_global', 'from-bootstrap');
    $app->addDeployStep('fixture-closure', static function (Kernel $app, SymfonyStyle $io) {
        file_put_contents($app->cacheDir . '/fixture-step.txt', 'ran:' . var_export($app->debug, true));
    }, 'Fixture closure step', after: 'cache');
    $app->addDeployStep('fixture-command', 'fixture:greet', 'Fixture command step');
    // A custom spam check, the way a package adds one (see FormsTest).
    $app->forms->addSpamCheck('word', static fn (mixed $word) => new Starlite\Tests\Fixtures\Spam\WordCheck(is_string($word) ? $word : 'casino'));
};
