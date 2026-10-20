<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use TinyTank\Console\Console;
use TinyTank\Tests\Fixtures\Command\GreetCommand;
use TinyTank\Tests\Fixtures\Command\PlainCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleTest extends FrameworkTestCase
{
    private const COMMANDS = __DIR__ . '/Fixtures/Command';
    private const NAMESPACE = 'TinyTank\\Tests\\Fixtures\\Command\\';

    // --- Command discovery --------------------------------------------------------

    public function testDiscoversConcreteCommandsOnly(): void
    {
        self::assertSame([GreetCommand::class, PlainCommand::class], Console::discover(self::COMMANDS, self::NAMESPACE));
        self::assertSame([], Console::discover('/does/not/exist', self::NAMESPACE));
    }

    public function testCommandsNeedingConstructorArgumentsAreRejected(): void
    {
        $this->expectExceptionMessage('commands in src/Command/ are created without arguments; extend AppCommand');
        Console::discover(__DIR__ . '/Fixtures/BadCommand', 'TinyTank\\Tests\\Fixtures\\BadCommand\\');
    }

    public function testClassNamesMustMatchTheirFiles(): void
    {
        $dir = $this->tempDir('commands');
        self::write($dir, ['Stray.php' => '<?php']);

        $this->expectExceptionMessage('must declare class Nowhere\\Stray');
        Console::discover($dir, 'Nowhere\\');
    }

    public function testListingCommandsDoesNotBootTheApp(): void
    {
        // No config at all: listing and plain commands still work; AppCommands boot the kernel lazily.
        $console = Console::application($this->tempDir('empty'), self::COMMANDS, self::NAMESPACE);

        self::assertTrue($console->has('fixture:greet'));
        self::assertTrue($console->has('fixture:plain'));
        self::assertTrue($console->has('deploy'));
        self::assertTrue($console->has('cache:clear'));
    }

    public function testAppCommandsGetTheKernel(): void
    {
        $tester = new CommandTester($this->console($this->project())->find('fixture:greet'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('greet from Fixture debug=false', self::text($tester));
    }

    // --- Deploy ---------------------------------------------------------------------

    public function testDeployStepsIncludeTheAppsStepsWhereTheyAsked(): void
    {
        $tester = $this->deploy($this->project(), ['--list-steps' => true, '--skip' => 'vite']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        preg_match_all('/^\s{2}(\S+)/m', $tester->getDisplay(), $rows);
        self::assertSame(
            ['Step', 'composer', 'cache', 'fixture-closure', 'routes', 'blog', 'pages', 'collections', 'embeds', 'translations', 'templates', 'vite', 'fixture-command', 'switch', 'opcache', 'cdn'],
            $rows[1],
        );
        self::assertStringContainsString('vite (skipped)', self::text($tester));
    }

    public function testDeployBuildsEverythingAndRunsAppSteps(): void
    {
        $root = $this->project();
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('3 blog posts compiled (4 language versions)', self::text($tester));
        self::assertStringContainsString('greet from Fixture debug=false', self::text($tester), 'command steps share the production kernel');
        self::assertStringNotContainsString('CDN', self::text($tester), 'CDN caching is off: nothing to purge');

        foreach (['routes.matcher.php', 'routes.generator.php', 'blog.php', 'twig'] as $file) {
            self::assertFileExists("{$root}/var/cache/{$file}");
        }
        self::assertNotEmpty(glob("{$root}/var/cache/translations/catalogue.it.*.php"));
        self::assertSame('ran:false', file_get_contents("{$root}/var/cache/fixture-step.txt"));
        self::assertFileExists("{$root}/var/cache/fixture-command.txt");
        self::assertFileExists("{$root}/public/media/blog/alpha/cover.png");
        self::assertDirectoryDoesNotExist("{$root}/public/media/blog/delta");
        self::assertDirectoryDoesNotExist("{$root}/var/deploy/next", 'built aside, then moved in');
    }

    public function testAFailedDeployLeavesTheLiveSiteAsItWas(): void
    {
        $root = $this->project();
        self::write($root, ['var/cache/.gitkeep' => '']);
        self::assertSame(Command::SUCCESS, $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none'])->getStatusCode());
        self::assertFileExists("{$root}/var/cache/.gitkeep", 'still committed in the skeleton');
        $matcher = (string) file_get_contents("{$root}/var/cache/routes.matcher.php");

        // The next deploy fails halfway: after the posts were compiled and their files published.
        self::write($root, ['config/bootstrap.php' => '<?php return static function (TinyTank\Kernel $app): void {
            $app->addDeployStep("fails", static fn () => false, after: "collections");
        };']);
        self::remove("{$root}/content/blog/2026/09/alpha");
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($matcher, file_get_contents("{$root}/var/cache/routes.matcher.php"), 'the live cache is untouched');
        self::assertFileExists("{$root}/var/cache/fixture-step.txt", 'all of it');
        self::assertFileExists("{$root}/public/media/blog/alpha/cover.png", 'and so are the published files');
    }

    public function testAMissingViteBuildStopsTheDeployBeforeTheSwitch(): void
    {
        $root = $this->project();
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'no vite.config.js: a site without Vite');
        self::assertStringContainsString('No Vite build', self::text($tester));

        self::write($root, ['vite.config.js' => 'export default {};']);
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('every page needs it: run `npm run build` first', self::text($tester));
        self::assertStringContainsString('Deploy stopped at step "vite"', self::text($tester));
        self::assertFileExists("{$root}/var/cache/routes.matcher.php", 'the site still answers, from the last deploy');

        self::write($root, ['public/build/.vite/manifest.json' => '{}']);
        self::assertSame(Command::SUCCESS, $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none'])->getStatusCode());
        self::assertFileExists("{$root}/var/cache/vite.php");
    }

    public function testRollbackPutsThePreviousDeployBack(): void
    {
        $root = $this->project();
        $options = ['--skip' => 'composer', '--opcache' => 'none'];
        $tester = $this->deploy($root, ['--rollback' => true] + $options);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no previous deploy to go back to', self::text($tester));

        $this->deploy($root, $options);
        $blog = (string) file_get_contents("{$root}/var/cache/blog.php");
        // The next deploy removes a post and its files.
        self::remove("{$root}/content/blog/2026/09/alpha");
        $this->deploy($root, $options);
        self::assertNotSame($blog, file_get_contents("{$root}/var/cache/blog.php"));
        self::assertDirectoryDoesNotExist("{$root}/public/media/blog/alpha");

        $tester = $this->deploy($root, ['--rollback' => true] + $options);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($blog, file_get_contents("{$root}/var/cache/blog.php"), 'the previous cache');
        self::assertFileExists("{$root}/public/media/blog/alpha/cover.png", 'and its files');

        $this->deploy($root, ['--rollback' => true] + $options);
        self::assertDirectoryDoesNotExist("{$root}/public/media/blog/alpha", 'a second rollback undoes the first');
    }

    public function testTheSwitchedCacheServesWithoutRebuildingAnything(): void
    {
        $root = $this->project();
        $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);
        $files = static fn (): array => array_map(static fn (\SplFileInfo $file) => $file->getPathname(), iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/var/cache", \FilesystemIterator::SKIP_DOTS)), false));
        $built = $files();

        $app = $this->bootKernel($root, false, ['cache_dir' => "{$root}/var/cache", 'content_dir' => "{$root}/content"]);
        self::assertSame(200, $this->request($app, '/')->getStatusCode());
        self::assertSame(200, $this->request($app, '/blog')->getStatusCode());
        self::assertSame($built, $files(), 'built in another folder, the cache is used as it is (nothing refers to where it was made)');
    }

    public function testDeployPurgesTheCdnLast(): void
    {
        $root = $this->project();
        $this->withCdn($root, ['enabled' => true, 'purge' => 'command', 'command' => 'echo "$@" > var/purged.txt']);
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('CDN purged (the purge command)', self::text($tester));
        self::assertSame("\n", file_get_contents("{$root}/var/purged.txt"), 'everything: no URLs');
    }

    public function testAFailedPurgeFailsTheDeployAndSaysWhatToDo(): void
    {
        $root = $this->project();
        $this->withCdn($root, ['enabled' => true, 'purge' => 'command', 'command' => 'exit 1']);
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the CDN still has the old pages', self::text($tester));
        self::assertStringContainsString('cdn:purge --all', self::text($tester));
    }

    public function testWithoutPurgingDeploySaysWhenChangesShow(): void
    {
        $root = $this->project();
        $this->withCdn($root, ['enabled' => true]);
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('changes reach it within 300 seconds', self::text($tester));
    }

    public function testDeployStopsAtAFailingStep(): void
    {
        $root = $this->project();
        self::write($root, ['config/bootstrap.php' => '<?php return static function (TinyTank\Kernel $app): void {
            $app->addDeployStep("fails", static fn () => false, after: "cache");
        };']);
        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Deploy stopped at step "fails"', self::text($tester));
        self::assertFileDoesNotExist("{$root}/var/cache/routes.matcher.php", 'later steps did not run');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidDeploys(): iterable
    {
        yield 'unknown skipped step' => [['--skip' => 'nope'], 'Unknown step(s) in --skip: nope'];
        yield 'unknown opcache mode' => [['--opcache' => 'magic'], 'Unknown --opcache mode "magic"'];
        yield 'reload without a command' => [['--opcache' => 'reload'], '--opcache=reload needs --reload-cmd'];
    }

    /** @param array<string, string> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDeploys')]
    public function testInvalidDeployOptionsChangeNothing(array $options, string $message): void
    {
        $root = $this->project();
        $tester = $this->deploy($root, $options);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', self::text($tester)) ?? '');
        self::assertDirectoryDoesNotExist("{$root}/var/cache/twig");
    }

    public function testAppStepWithUnknownAnchorIsRejected(): void
    {
        $root = $this->project();
        self::write($root, ['config/bootstrap.php' => '<?php return static function (TinyTank\Kernel $app): void {
            $app->addDeployStep("lost", static fn () => true, before: "nope");
        };']);
        $tester = $this->deploy($root, ['--list-steps' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('refers to unknown step "nope"', self::text($tester));
    }

    public function testCacheClearKeepsGitkeep(): void
    {
        $root = $this->project();
        self::write($root, ['var/cache/.gitkeep' => '', 'var/cache/x/y.php' => '<?php', 'public/media/blog/a/b.png' => 'x', 'public/media/pages/about/c.png' => 'x']);
        $tester = new CommandTester($this->console($root)->find('cache:clear'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['.gitkeep'], array_values(array_diff((array) scandir("{$root}/var/cache"), ['.', '..'])));
        self::assertSame([], array_values(array_diff((array) scandir("{$root}/public/media/blog"), ['.', '..'])));
        self::assertSame([], array_values(array_diff((array) scandir("{$root}/public/media/pages"), ['.', '..'])));
    }

    public function testSiteCleanAsksListsAndRemovesTheExamples(): void
    {
        $root = $this->project();
        self::write($root, [
            '.examples' => "templates/demo.twig\n",
            'templates/demo.twig' => 'demo',
            'config/extra.php' => "<?php\n// example:start\n\$demo = true;\n// example:end\n",
        ]);
        $tester = new CommandTester($this->console($root)->find('site:clean'));

        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('templates/demo.twig', $tester->getDisplay());
        self::assertStringContainsString('config/extra.php (1)', $tester->getDisplay());
        self::assertFileExists("{$root}/templates/demo.twig", 'a dry run changes nothing');

        $tester->setInputs(['no']);
        $tester->execute([]);
        self::assertFileExists("{$root}/templates/demo.twig", 'answering no changes nothing');

        $tester->setInputs(['yes']);
        $tester->execute([]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist("{$root}/templates/demo.twig");
        self::assertSame("<?php\n", file_get_contents("{$root}/config/extra.php"));
        self::assertStringContainsString('The examples are gone.', $tester->getDisplay());

        $tester->execute([]);
        self::assertStringContainsString('already clean', $tester->getDisplay());
    }

    public function testDeployWarnsWhileTheExamplesAreThere(): void
    {
        $root = $this->project();
        self::write($root, ['.examples' => "templates/demo.twig\n"]);

        $tester = $this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'a warning, not an error');
        self::assertStringContainsString('still has TinyTank\'s starter examples', self::text($tester));
        self::assertStringContainsString('php bin/console site:clean', self::text($tester));

        unlink("{$root}/.examples");
        self::assertStringNotContainsString('starter examples', self::text($this->deploy($root, ['--skip' => 'composer', '--opcache' => 'none'])));
    }

    public function testCacheClearRestoresTheDevelopmentAutoloaderAfterADeploy(): void
    {
        $root = $this->project();
        // A stand-in for Composer that records how it was called.
        self::write($root, ['bin/fake-composer' => "#!/bin/sh\necho \"\$@\" >> composer.log\nexit \${FAKE_EXIT:-0}\n"]);
        chmod("{$root}/bin/fake-composer", 0755);
        $run = fn (): int => (new CommandTester($this->console($root)->find('cache:clear')))->execute(['--composer' => "{$root}/bin/fake-composer"]);

        // Normal autoloader: Composer isn't called.
        self::write($root, ['vendor/composer/autoload_real.php' => '<?php // $loader->register(true);']);
        self::assertSame(Command::SUCCESS, $run());
        self::assertFileDoesNotExist("{$root}/composer.log");

        // deploy's authoritative class map: rebuilt without it.
        self::write($root, ['vendor/composer/autoload_real.php' => '<?php $loader->setClassMapAuthoritative(true);']);
        self::assertSame(Command::SUCCESS, $run());
        self::assertSame("dump-autoload --no-interaction --quiet\n", file_get_contents("{$root}/composer.log"));

        putenv('FAKE_EXIT=1');
        try {
            self::assertSame(Command::FAILURE, $run());
        } finally {
            putenv('FAKE_EXIT');
        }
    }

    private static function remove(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /** A writable copy of the fixture project, with the fixture content inside it. */
    private function project(): string
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        rename($this->copyToTemp(self::CONTENT, 'content'), $root . '/content');

        return $root;
    }

    /**
     * The command's output with Symfony's line wrapping undone: messages wrap at the terminal's width
     * (80 columns in CI), so a phrase can be split across lines and boxes ("[ERROR]", " ! [NOTE]").
     */
    private static function text(CommandTester $tester): string
    {
        return (string) preg_replace('/ *\n(?: *!)? +/', ' ', $tester->getDisplay());
    }

    /** @param array<string, mixed> $cdn config/app.php `cdn` for the copied project */
    private function withCdn(string $root, array $cdn): void
    {
        rename("{$root}/config/app.php", "{$root}/config/app.base.php");
        self::write($root, ['config/app.php' => '<?php return (require __DIR__ . "/app.base.php") + ["cdn" => ' . var_export($cdn, true) . '];']);
    }

    private function console(string $root): Application
    {
        return Console::application($root, self::COMMANDS, self::NAMESPACE);
    }

    /** @param array<string, mixed> $options */
    private function deploy(string $root, array $options): CommandTester
    {
        $tester = new CommandTester($this->console($root)->find('deploy'));
        $tester->execute($options);

        return $tester;
    }
}
