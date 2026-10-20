<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TinyTank\Console\Console;

/** `bin/console check`: the preflight that deploy also runs first. */
final class CheckTest extends FrameworkTestCase
{
    private const SECRET = 'a3f1c9e07b2d4568a3f1c9e07b2d4568a3f1c9e07b2d4568a3f1c9e07b2d4568';

    private string $originalSecret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSecret = (string) getenv('APP_SECRET');
        putenv('APP_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv('APP_SECRET=' . $this->originalSecret);
        parent::tearDown();
    }

    /** @return array{int, array<string, array{status: string, message: string}>, string} exit code, results by check, display */
    private function check(string $root): array
    {
        $tester = new CommandTester(Console::application($root)->find('check'));
        $tester->execute(['--json' => true]);
        $json = json_decode($tester->getDisplay(), true);
        self::assertIsArray($json, $tester->getDisplay());
        $results = [];
        foreach ($json['checks'] as $result) {
            $results[$result['check']] = ['status' => $result['status'], 'message' => $result['message']];
        }
        self::assertSame($tester->getStatusCode() === Command::SUCCESS, $json['ok']);

        return [$tester->getStatusCode(), $results, $tester->getDisplay()];
    }

    private function project(): string
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        rename($this->copyToTemp(self::CONTENT, 'content'), $root . '/content');

        return $root;
    }

    public function testAReadySiteAndNothingWritten(): void
    {
        $root = $this->project();
        [$code, $results] = $this->check($root);

        self::assertSame(Command::SUCCESS, $code, (string) json_encode($results));
        self::assertSame(['php', 'environment', 'writable', 'content', 'images', 'templates', 'pages', 'vite'], array_keys($results));
        self::assertStringContainsString('3 posts, ', $results['content']['message']);
        self::assertDirectoryDoesNotExist("{$root}/var/cache", 'read-only: compiled in a temporary folder');
        self::assertDirectoryDoesNotExist("{$root}/public/media");

        $tester = new CommandTester(Console::application($root)->find('check'));
        $tester->execute([]);
        self::assertStringContainsString('✔ content:', $tester->getDisplay());
        self::assertStringContainsString('Ready to deploy.', $tester->getDisplay());
    }

    public function testWhatWouldBreakTheSiteIsAnError(): void
    {
        $root = $this->project();
        putenv('APP_SECRET=change-me');
        file_put_contents("{$root}/content/pages/legal/index.md", preg_replace('/^---\n/', "---\ntemplate: pages/missing.twig\nform: newsletter\n", (string) file_get_contents("{$root}/content/pages/legal/index.md"), 1));
        self::write($root, [
            'vite.config.js' => 'export default {};',
            'public/build/.vite/manifest.json' => '{"resources/js/app.js": {"file": "app.js"}}',
            'templates/page.twig' => "{{ vite('resources/js/app.js', page_scripts ?? []) }}{% set page_scripts = ['resources/js/pages/map.js'] %}",
        ]);

        [$code, $results] = $this->check($root);

        self::assertSame(Command::FAILURE, $code);
        self::assertSame('error', $results['environment']['status']);
        self::assertStringContainsString('openssl rand -hex 32', $results['environment']['message']);
        self::assertSame('error', $results['templates']['status']);
        self::assertStringContainsString('legal/index.md: its template pages/missing.twig doesn\'t exist.', $results['templates']['message'], 'it would 500 when visited');
        self::assertStringContainsString('the form "newsletter" isn\'t in config/forms.php', $results['templates']['message']);
        self::assertSame('error', $results['vite']['status']);
        self::assertStringContainsString('Templates use resources/js/pages/map.js, not in the Vite build', $results['vite']['message']);

        unlink("{$root}/public/build/.vite/manifest.json");
        self::assertStringContainsString('run `npm run build`', $this->check($root)[1]['vite']['message']);
    }

    public function testContentThatDoesntCompileIsAnError(): void
    {
        $root = $this->project();
        file_put_contents("{$root}/content/pages/legal/index.md", "---\ntitle: Legal\nsumary: typo\n---\nText\n");

        [$code, $results] = $this->check($root);

        self::assertSame(Command::FAILURE, $code);
        self::assertSame('error', $results['content']['status']);
        self::assertStringContainsString('sumary', $results['content']['message']);
    }

    public function testDeployRunsItFirstAndStopsOnAnError(): void
    {
        $root = $this->project();
        self::write($root, ['vite.config.js' => 'export default {};', 'public/build/.vite/manifest.json' => '{}', 'templates/page.twig' => "{{ vite('resources/js/app.js') }}"]);
        $tester = new CommandTester(Console::application($root)->find('deploy'));
        $tester->execute(['--skip' => 'composer', '--opcache' => 'none']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Deploy stopped at step "check"', $tester->getDisplay());
        self::assertDirectoryDoesNotExist("{$root}/var/cache", 'nothing built or switched');
    }
}
