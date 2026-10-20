<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TinyTank\Console\Console;
use TinyTank\Console\StaticBuildCommand;
use TinyTank\Static\Builder;

/** `bin/console build:static`: the site rendered into plain files, found by following links. */
final class StaticBuildTest extends FrameworkTestCase
{
    private function project(): string
    {
        $root = $this->copyToTemp(self::PROJECT, 'project');
        rename($this->copyToTemp(self::CONTENT, 'content'), $root . '/content');
        self::write($root, ['public/favicon.svg' => '<svg/>', 'public/index.php' => '<?php', 'public/build/.vite/manifest.json' => '{}', 'public/build/app.js' => '']);
        // Without the fixture's route that fails on purpose (for the error page tests).
        $routes = "{$root}/config/routes.php";
        file_put_contents($routes, (string) preg_replace("#^.*'/boom'.*\n#m", '', (string) file_get_contents($routes)));

        return $root;
    }

    public function testEveryLinkedPageBecomesAFile(): void
    {
        $root = $this->project();
        $out = $this->tempDir('out');
        $app = $this->kernel(false, ['url' => 'https://static.test', 'cache_dir' => $this->tempDir('cache')], $root);

        $build = (new Builder($app, $out, ['/legal/privacy']))->run();

        // Cloudflare Pages by default: <path>.html, served there at the same URL.
        foreach (['index.html', 'sitemap.xml', 'robots.txt', 'blog/feed.xml', '404.html', 'legal/privacy.html', 'blog/alpha.html', 'it/blog/alpha.html', 'favicon.svg', 'build/app.js', 'media/blog/alpha/cover.png', '_headers'] as $file) {
            self::assertFileExists("{$out}/{$file}", $file);
        }
        self::assertFileDoesNotExist("{$out}/index.php", 'no PHP in a static site');
        self::assertStringContainsString('https://static.test/blog/alpha', (string) file_get_contents("{$out}/sitemap.xml"), 'absolute URLs use the base URL');
        self::assertSame(['file' => 'blog/alpha.html', 'type' => 'text/html; charset=utf-8'], array_intersect_key($build->pages['/blog/alpha'] ?? [], ['file' => 1, 'type' => 1]));
        self::assertStringContainsString("/*\n  Content-Security-Policy: default-src 'self'", (string) file_get_contents("{$out}/_headers"));
        self::assertStringContainsString("default-src 'self'", $build->pages['/blog/alpha']['headers']['Content-Security-Policy'] ?? '', 'the headers each page would get, for the host');
        self::assertStringContainsString('404', (string) file_get_contents("{$out}/404.html"));
        self::assertSame([], $build->errors);
    }

    public function testWhatNeedsTheServerIsReportedByPage(): void
    {
        $root = $this->project();
        $out = $this->tempDir('out');
        $app = $this->kernel(false, ['url' => 'https://static.test', 'cache_dir' => $this->tempDir('cache')], $root);

        $build = (new Builder($app, $out))->run();

        // The fixture's home page has a Datastar action rendering a template on the server.
        self::assertSame(['a Datastar GET action to the server (template _partials/greet)'], $build->dynamic['/'] ?? null);
        self::assertArrayNotHasKey('/blog/alpha', $build->dynamic);
    }

    public function testTemplatesKnowItsAStaticBuild(): void
    {
        $root = $this->project();
        $app = $this->kernel(false, ['url' => 'https://static.test', 'cache_dir' => $this->tempDir('cache')], $root);
        $template = $app->twig->createTemplate('{{ site.static ? "static" : "dynamic" }}');

        self::assertSame('dynamic', $template->render());
        (new Builder($app, $this->tempDir('out')))->run();
        self::assertSame('static', $template->render());
    }

    public function testAStaticFormPostsToTheHostWhichGetsWhatItNeeds(): void
    {
        $root = $this->project();
        self::write($root, [
            'config/forms.php' => "<?php return ['contact' => ['fields' => ['email' => ['type' => 'email', 'required' => true], 'message' => ['type' => 'textarea', 'min' => 10]], 'to' => 'me@example.com', 'subject' => 'From {email}', 'spam' => ['honeypot', 'max_links' => 1], 'static' => ['from' => 'form@forms.example.com']]];",
            'templates/custom-page.twig' => "{% extends '_layout.twig' %}{% block content %}<h1>{{ page.title }}</h1><form method=\"post\" action=\"{{ form_action('contact', '/contact') }}\">{{ form_spam('contact') }}</form>{% endblock %}",
        ]);
        $app = $this->kernel(false, ['url' => 'https://static.test', 'cache_dir' => $this->tempDir('cache'), 'forms' => require "{$root}/config/forms.php"], $root);
        $out = $this->tempDir('out');

        // The PHP site posts to the page itself, with the usual spam checks.
        $php = self::body($this->request($app, '/contact'));
        self::assertStringContainsString('action="/contact"', $php);
        self::assertStringNotContainsString('name="_page"', $php);

        $build = (new Builder($app, $out))->run();
        $html = (string) file_get_contents("{$out}/contact.html");
        self::assertStringContainsString('action="/forms/contact"', $html, 'the host answers it');
        self::assertStringContainsString('name="website"', $html, 'the honeypot works without a server');
        self::assertStringContainsString('<input type="hidden" name="_page" value="/contact">', $html, 'where to send the visitor back');
        self::assertStringContainsString('name="_page" value="/it/contact"', (string) file_get_contents("{$out}/it/contact.html"), 'each language its own page');
        self::assertArrayNotHasKey('/contact', $build->dynamic, 'not a server feature any more');
        self::assertFileExists("{$out}/contact/sent.html", 'the "sent" state, where the host sends visitors');

        $form = $build->forms['contact'] ?? [];
        self::assertSame(['/contact' => 'en', '/it/contact' => 'it'], $form['pages'] ?? null);
        self::assertSame([['me@example.com'], 'form@forms.example.com', 'email', true, 1], [$form['to'], $form['from'], $form['reply_to'], $form['honeypot'], $form['max_links']]);

        // The published site's recipient doesn't have to be the development one (DDEV's Mailpit…).
        $forms = require "{$root}/config/forms.php";
        $forms['contact']['static']['to'] = 'inbox@example.com';
        $live = $this->kernel(false, ['url' => 'https://static.test', 'cache_dir' => $this->tempDir('cache2'), 'forms' => $forms], $root);
        self::assertSame(['inbox@example.com'], (new Builder($live, $this->tempDir('out2')))->run()->forms['contact']['to']);
        self::assertSame('From {email}', $form['subject']['en'] ?? null, 'placeholders kept for the host');
        self::assertSame('Use at least {min} characters.', $form['messages']['en']['min'] ?? null);
        self::assertSame('Campo obbligatorio.', $form['messages']['it']['required'] ?? null, 'in each page\'s language');
    }

    public function testBrokenMediaLinksAreErrors(): void
    {
        $root = $this->project();
        file_put_contents("{$root}/content/pages/legal/index.md", (string) file_get_contents("{$root}/content/pages/legal/index.md") . "\n[Gone](/media/nowhere/gone.pdf)\n");
        $out = $this->tempDir('out');
        $app = $this->kernel(false, ['url' => 'https://static.test', 'cache_dir' => $this->tempDir('cache'), 'content_dir' => "{$root}/content"], $root);

        $build = (new Builder($app, $out))->run();

        self::assertSame('is missing (linked from /legal)', $build->errors['/media/nowhere/gone.pdf'] ?? null);
    }

    public function testTheCommandBuildsIntoItsOwnFolderOnly(): void
    {
        $root = $this->project();
        $tester = new CommandTester(Console::application($root)->find('build:static'));

        $tester->execute(['--base-url' => 'https://static.test', '--allow-dynamic' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists("{$root}/deploy/index.html");
        self::assertFileExists("{$root}/deploy/_headers", 'Cloudflare Pages by default');
        self::assertFileExists("{$root}/deploy/" . StaticBuildCommand::MARKER);
        self::assertStringContainsString('These need the server', $tester->getDisplay());

        $tester->execute(['--base-url' => 'https://static.test']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'server features fail the build unless allowed');

        // A rebuild empties only its own folder; the site's folders and others' files are refused.
        file_put_contents("{$root}/deploy/stale.html", 'old');
        $tester->execute(['--base-url' => 'https://static.test', '--allow-dynamic' => true]);
        self::assertFileDoesNotExist("{$root}/deploy/stale.html");
        foreach (['.' => 'can\'t be the site', 'public' => 'site\'s own public/', 'content/x' => 'site\'s own content/', '..' => 'can\'t be the site'] as $out => $message) {
            $tester->execute(['--out' => $out, '--base-url' => 'https://static.test']);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), $out);
            self::assertStringContainsString($message, str_replace("\n", ' ', $tester->getDisplay()), $out);
        }
        self::write($root, ['mine/notes.txt' => 'keep']);
        $tester->execute(['--out' => 'mine', '--base-url' => 'https://static.test']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileExists("{$root}/mine/notes.txt", 'never emptied');

        $tester->execute(['--base-url' => 'not a url']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());

        $tester = new CommandTester(Console::application($root)->find('build:static'));
        $tester->execute(['--base-url' => 'https://static.test', '--host' => 'netlifyy']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Unknown static host "netlifyy": use cloudflare, none, or a class implementing', str_replace("\n", ' ', (string) preg_replace('/\s+/', ' ', $tester->getDisplay())));

        $tester = new CommandTester(Console::application($root)->find('build:static'));
        $tester->execute(['--base-url' => 'https://static.test', '--host' => 'none', '--allow-dynamic' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist("{$root}/deploy/_headers", 'no host files for "none"');
    }
}
