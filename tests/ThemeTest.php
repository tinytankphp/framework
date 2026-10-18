<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use TinyTank\Theme;

final class ThemeTest extends FrameworkTestCase
{
    public function testTheScriptIsPrintedInTheHeadOfEveryPage(): void
    {
        $html = $this->body($this->request($this->kernel(), '/'));

        self::assertStringContainsString('<head>' . "\n" . '<script>' . Theme::SCRIPT . '</script>', $html);
    }

    public function testTheScriptReadsTheKeyAndSignalThatThemeSaves(): void
    {
        // resources/js/tinytank.js: persist(['_theme'], { key: 'tinytank-theme' }).
        $js = (string) file_get_contents(dirname(__DIR__) . '/resources/js/tinytank.js');

        self::assertStringContainsString("persist(['" . Theme::SIGNAL . "'], { key: '" . Theme::STORAGE_KEY . "' })", $js);
        self::assertStringContainsString('localStorage.getItem("tinytank-theme")', Theme::SCRIPT);
        self::assertStringContainsString('._theme}', Theme::SCRIPT);
    }

    public function testHashForAContentSecurityPolicy(): void
    {
        self::assertSame("'sha256-" . base64_encode(hash('sha256', Theme::SCRIPT, true)) . "'", Theme::hash());
        self::assertMatchesRegularExpression("#^'sha256-[A-Za-z0-9+/]{43}='$#", Theme::hash());
    }

    public function testASiteCanDefaultToATheme(): void
    {
        $app = $this->kernel(overrides: ['theme' => ['default' => 'dark']]);
        $response = $this->request($app, '/');

        self::assertStringContainsString('<script>' . Theme::script('dark') . '</script>', $this->body($response));
        self::assertStringContainsString(':"dark"})()', Theme::script('dark'), 'no saved choice: dark, whatever the system says');
        self::assertStringNotContainsString('matchMedia', Theme::script('dark'));
        self::assertStringContainsString('t==="light"||t==="dark"?t', Theme::script('dark'), 'a saved choice still wins');
        self::assertStringContainsString(Theme::hash('dark'), (string) $response->headers->get('Content-Security-Policy'), 'the CSP allows this script');
    }

    public function testAnUnknownDefaultIsRefused(): void
    {
        $this->expectExceptionMessage('theme.default is "system", "light" or "dark"');
        $this->kernel(overrides: ['theme' => ['default' => 'neon']]);
    }
}
