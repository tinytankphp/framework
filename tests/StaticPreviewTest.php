<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TinyTank\Console\Console;
use TinyTank\Console\StaticBuildCommand;
use TinyTank\Console\StaticPreviewCommand;

/** `bin/console preview:static`: the build served locally, as its host will. */
final class StaticPreviewTest extends FrameworkTestCase
{
    private function tester(string $root): CommandTester
    {
        return new CommandTester(Console::application($root)->find('preview:static'));
    }

    public function testItNeedsABuild(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        $tester = $this->tester($root);

        $tester->execute([]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('run `php bin/console build:static` first', $tester->getDisplay());
    }

    public function testCloudflaresLocalServerOrPhps(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        self::write($root, ['deploy/' . StaticBuildCommand::MARKER => '', 'deploy/wrangler.jsonc' => '{}', 'deploy/index.html' => 'home']);
        $tester = $this->tester($root);

        $tester->execute(['--dry-run' => true]);
        self::assertSame("cd '{$root}/deploy' && npx --yes wrangler@4 dev --ip '0.0.0.0' --port '8080'", trim($tester->getDisplay()), 'Cloudflare\'s, reachable from DDEV');

        self::write($root, ['node_modules/.bin/wrangler' => '']);
        $tester->execute(['--dry-run' => true, '--port' => '9000', '--ip' => '127.0.0.1']);
        self::assertStringContainsString("'{$root}/node_modules/.bin/wrangler' dev --ip '127.0.0.1' --port '9000'", $tester->getDisplay(), 'the site\'s own wrangler first');

        $tester->execute(['--dry-run' => true, '--php' => true]);
        self::assertStringContainsString(" -S '0.0.0.0:8080' -t '{$root}/deploy' '" . realpath(StaticPreviewCommand::ROUTER) . "'", $tester->getDisplay());

        $tester->execute(['--dry-run' => true, '--port' => 'eighty']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testThePhpRouterServesLikeAStaticHost(): void
    {
        $out = $this->tempDir('deploy');
        self::write($out, [
            'index.html' => 'home', 'about.html' => 'about', 'about/credits.html' => 'credits', 'blog/index.html' => 'blog',
            '404.html' => 'missing', 'style.css' => 'body{}', 'wrangler.jsonc' => '{"secret":1}',
            '_redirects' => "# rules\n/old /about 301\n",
        ]);
        $port = 18000 + random_int(0, 999);
        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $out, StaticPreviewCommand::ROUTER], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['TINYTANK_STATIC_ROOT' => $out]);
        self::assertIsResource($process);
        try {
            $get = static function (string $path) use ($port): array {
                for ($i = 0; $i < 50; ++$i) {
                    $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]));
                    if ($body !== false) {
                        return [(int) explode(' ', $http_response_header[0])[1], $body, implode("\n", $http_response_header)];
                    }
                    usleep(100_000);
                }
                self::fail('The preview server did not start.');
            };

            self::assertSame([200, 'home'], array_slice($get('/'), 0, 2));
            self::assertSame([200, 'about'], array_slice($get('/about'), 0, 2), '/about from about.html');
            self::assertSame([200, 'credits'], array_slice($get('/about/credits'), 0, 2));
            self::assertSame([200, 'blog'], array_slice($get('/blog'), 0, 2), 'or from blog/index.html');
            self::assertSame([200, 'body{}'], array_slice($get('/style.css'), 0, 2));
            [$status, , $headers] = $get('/old');
            self::assertSame(301, $status);
            self::assertStringContainsString('Location: /about', $headers, '_redirects');
            self::assertSame([404, 'missing'], array_slice($get('/nope'), 0, 2));
            self::assertSame([404, 'missing'], array_slice($get('/wrangler.jsonc'), 0, 2), 'the host\'s files stay private');
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
