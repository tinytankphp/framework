<?php

declare(strict_types=1);

namespace TinyTank\Tests;

final class LanguageTest extends FrameworkTestCase
{
    public function testSecondaryLanguageIsServedUnderItsPrefix(): void
    {
        $app = $this->kernel();
        $html = self::body($this->request($app, '/it'));

        self::assertStringContainsString('<html lang="it">', $html);
        self::assertStringContainsString('<h1>Benvenuto</h1>', $html);
        self::assertStringContainsString('<p id="link">/it/about</p>', $html, 'path() keeps the current language');
        self::assertSame('hello ada in it', self::body($this->request($app, '/it/hello/ada')));
    }

    public function testDefaultLanguagePrefixRedirectsToTheUnprefixedUrl(): void
    {
        $app = $this->kernel();

        $response = $this->request($app, '/en/about?x=1');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/about?x=1', $response->headers->get('Location'));

        self::assertSame('/', $this->request($app, '/en')->headers->get('Location'));
    }

    public function testThatRedirectNeverLeavesTheSite(): void
    {
        $app = $this->kernel();

        // "//evil.example" as a Location is another host; "/\evil.example" too, for a browser.
        self::assertSame('/evil.example?x=1', $this->request($app, '/en//evil.example?x=1')->headers->get('Location'));
        self::assertSame('/evil.example/path', $this->request($app, '/en///evil.example/path')->headers->get('Location'));
        // Symfony's Request::create() refuses a backslash; a web server may pass one on.
        $raw = new \Symfony\Component\HttpFoundation\Request(server: ['REQUEST_URI' => '/en/\\evil.example', 'REQUEST_METHOD' => 'GET']);
        self::assertSame('/evil.example', $app->handle($raw)->headers->get('Location'));
        self::assertSame('/', $this->request($app, '/en//')->headers->get('Location'));
    }

    public function testTheSwitcherNeverLinksAnotherHost(): void
    {
        $app = $this->kernel();

        foreach (['/it//evil.example/x', '//evil.example/x', '/it/\\evil.example/x'] as $path) {
            $response = $app->handle(new \Symfony\Component\HttpFoundation\Request(server: ['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET']));
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertStringNotContainsString('href="//', self::body($response), $path);
            self::assertStringNotContainsString('href="/\\', self::body($response), $path);
        }
    }

    public function testPrefixMustBeAWholeSegment(): void
    {
        self::assertSame(404, $this->request($this->kernel(), '/italy')->getStatusCode());
    }

    public function testTrailingSlashVariantHasTheSameCanonicalUrl(): void
    {
        $app = $this->kernel();
        $canonical = '<link rel="canonical" href="https://example.test/it">';

        self::assertStringContainsString($canonical, self::body($this->request($app, '/it')));
        self::assertStringContainsString($canonical, self::body($this->request($app, '/it/')));
    }

    public function testErrorPagesAreTranslated(): void
    {
        $response = $this->request($this->kernel(), '/it/nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Pagina non trovata.', self::body($response));
    }

    public function testSwitcherLinksTheSamePageInEveryLanguage(): void
    {
        $html = self::body($this->request($this->kernel(), '/it/about'));

        self::assertStringContainsString('href="/about" data-available="yes">en</a>', $html);
        self::assertStringContainsString('class="lang active" href="/it/about"', $html);
    }

    public function testDefaultLanguageIsConfigurable(): void
    {
        // An Italian-first site that added English later: Italian has no prefix, English gets /en/.
        $app = $this->kernel(overrides: ['language' => 'it']);

        self::assertStringContainsString('<h1>Benvenuto</h1>', self::body($this->request($app, '/')));
        self::assertStringContainsString('<h1>Welcome</h1>', self::body($this->request($app, '/en')));
        self::assertSame('/about', $this->request($app, '/it/about')->headers->get('Location'));
    }
}
