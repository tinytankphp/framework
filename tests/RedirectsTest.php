<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use Symfony\Component\Console\Tester\CommandTester;
use TinyTank\Console\Console;
use TinyTank\Redirects;
use TinyTank\Static\Builder;

/** config/redirects.php: pages that moved keep their old addresses. */
final class RedirectsTest extends FrameworkTestCase
{
    private const MAP = [
        '/old-legal' => '/legal',
        '/it/vecchio' => '/it/legal',
        '/sale/' => ['to' => '/blog', 'status' => 302],
        '/docs' => 'https://docs.example.com/start',
        '/terms' => '/legal#terms',
    ];

    public function testOldAddressesGoWhereThePagesWent(): void
    {
        $app = $this->kernel(false, ['redirects' => self::MAP]);

        $response = $this->request($app, '/old-legal');
        self::assertSame([301, '/legal'], [$response->getStatusCode(), $response->headers->get('Location')]);
        self::assertSame('/legal?page=2&utm=x', $this->request($app, '/old-legal/?page=2&utm=x')->headers->get('Location'), 'the query kept; /old/ is /old');
        self::assertSame('/it/legal', $this->request($app, '/it/vecchio')->headers->get('Location'), 'each language\'s own path');
        self::assertSame([302, '/blog'], [$this->request($app, '/sale')->getStatusCode(), $this->request($app, '/sale')->headers->get('Location')]);
        self::assertSame('https://docs.example.com/start', $this->request($app, '/docs')->headers->get('Location'));
        self::assertSame('/legal?a=1#terms', $this->request($app, '/terms?a=1')->headers->get('Location'), 'the query before the anchor');
        self::assertSame(200, $this->request($app, '/legal')->getStatusCode(), 'everything else as before');
    }

    public function testMistakesAreClearWhenTheSiteStarts(): void
    {
        foreach ([
            [['old' => '/new'], 'the old address is a path of this site, starting with /'],
            [['/old?x=1' => '/new'], 'without ?query or #anchor'],
            [['/old' => 'new'], '"new" is a path of this site (/new-page) or a full address'],
            [['/old' => ['to' => '/new', 'status' => 303]], '"status" is 301'],
            [['/old' => ['go' => '/new']], "or ['to' => '/new-page', 'status' => 302]"],
            [['/old' => '/old/'], 'it redirects to itself'],
            [['/a' => '/b', '/b' => '/c'], '"/a": "/b" redirects too, to "/c". Point "/a" straight at where it ends'],
        ] as [$map, $message]) {
            try {
                new Redirects($map);
                self::fail("Must be refused: {$message}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        // Read from config/redirects.php.
        $root = $this->copyToTemp(self::PROJECT, 'project');
        self::write($root, ['config/redirects.php' => "<?php return ['/x' => '/legal'];"]);
        self::assertSame('/legal', $this->kernel(false, [], $root)->redirects->find('/x')['to'] ?? null);
    }

    public function testAStaticBuildWritesThemForTheHost(): void
    {
        $out = $this->tempDir('out');
        $app = $this->kernel(false, ['url' => 'https://static.test', 'cache_dir' => $this->tempDir('cache'), 'redirects' => self::MAP]);
        $build = (new Builder($app, $out))->run();

        self::assertSame(['to' => '/legal', 'status' => 301], $build->redirects['/old-legal'] ?? null);
        self::assertSame(['to' => 'https://docs.example.com/start', 'status' => 301], $build->redirects['/docs'] ?? null, 'to another site too');
        self::assertStringContainsString("/sale /blog 302\n", (string) file_get_contents("{$out}/_redirects"));
        self::assertSame([], array_diff_key($build->errors, ['/boom' => 1, '/it/boom' => 1]), 'the targets were built (the fixture\'s /boom fails on purpose)');
        self::assertFileExists("{$out}/legal.html");
    }

    public function testCheckFindsAHiddenPageAndATargetThatDoesntAnswer(): void
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        rename($this->copyToTemp(self::CONTENT, 'content'), $root . '/content');
        $previous = (string) getenv('APP_SECRET');
        putenv('APP_SECRET=' . str_repeat('ab', 32));
        try {
            $check = static function () use ($root): array {
                $tester = new CommandTester(Console::application($root)->find('check'));
                $tester->execute(['--json' => true]);
                $results = array_column(json_decode($tester->getDisplay(), true)['checks'], null, 'check');

                return $results['redirects'] ?? [];
            };

            self::write($root, ['config/redirects.php' => "<?php return ['/old-legal' => '/legal'];"]);
            self::assertSame('ok', $check()['status']);

            self::write($root, ['config/redirects.php' => "<?php return ['/legal' => '/about', '/gone' => '/nowhere'];"]);
            $result = $check();
            self::assertSame('error', $result['status']);
            self::assertStringContainsString('/legal still has a page, which is never shown', $result['message']);
            self::assertStringContainsString('/gone goes to /nowhere, which answers 404', $result['message']);
        } finally {
            putenv("APP_SECRET={$previous}");
        }
    }
}
