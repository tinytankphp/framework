<?php

declare(strict_types=1);

namespace TinyTank;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Light / dark / system theme without a flash of the wrong colours.
 *
 * `{{ theme_script() }}` goes at the top of `<head>`: a tiny inline script that sets
 * `<html data-theme="light|dark">` before the page is painted, from the visitor's saved choice or
 * else their system setting. In the browser, `theme()` from the 'tinytank' module keeps it in step
 * with the `_theme` signal ('light', 'dark' or 'system'), saves the choice and follows system changes.
 *
 * The script is the same on every page and for every visitor (pages stay cacheable), so a Content
 * Security Policy allows it by its hash ({@see Csp} adds it).
 */
final class Theme extends AbstractExtension
{
    /** localStorage key and signal; resources/js/tinytank.js uses the same two. */
    public const STORAGE_KEY = 'tinytank-theme';
    public const SIGNAL = '_theme';

    // The saved value is persist()'s format: {"_theme": "dark"}. Anything else means "system"
    // (or the site's default theme, see script()).
    public const SCRIPT = '(function(){var t;try{t=JSON.parse(localStorage.getItem("' . self::STORAGE_KEY . '")||"{}").' . self::SIGNAL . '}catch(e){}'
        . 'document.documentElement.dataset.theme=t==="light"||t==="dark"?t:matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"})()';

    /** What a visitor who hasn't chosen gets: their system setting, or a fixed theme. */
    public const DEFAULTS = ['system', 'light', 'dark'];

    /** @param string $default config/app.php `theme.default`: 'system' (the default), 'light' or 'dark' */
    public function __construct(public readonly string $default = 'system')
    {
        if (!in_array($default, self::DEFAULTS, true)) {
            throw new \InvalidArgumentException('config/app.php theme.default is "system", "light" or "dark" (got "' . $default . '").');
        }
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('theme_script', $this->tag(...), ['is_safe' => ['html']])];
    }

    public function tag(): string
    {
        return '<script>' . self::script($this->default) . '</script>';
    }

    /**
     * The script for a default theme: SCRIPT for 'system'; for 'light' or 'dark', a visitor without a
     * saved choice gets that theme instead of their system's.
     */
    public static function script(string $default = 'system'): string
    {
        return $default === 'system' ? self::SCRIPT : str_replace(
            ':matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"})()',
            ':' . json_encode($default) . '})()',
            self::SCRIPT,
        );
    }

    /** CSP source for the script, e.g. "'sha256-…'" in `script-src`. */
    public static function hash(string $default = 'system'): string
    {
        return Csp::hash(self::script($default));
    }
}
