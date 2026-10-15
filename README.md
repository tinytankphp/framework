# TinyTank framework

The core of [TinyTank](https://tinytank.dev/): a tiny, database-free PHP
micro framework for static-like dynamic sites. Datastar for reactivity, Symfony Routing, Twig, a
Markdown blog with translations, SEO (Open Graph, JSON-LD, hreflang, sitemap, feeds) and Vite,
all compiled ahead of time for Opcache. Freely inspired by [Craft CMS](https://craftcms.com) and
[Datastar](https://data-star.dev).

**This is the framework package (`tinytank/framework`). To build a site, start from the skeleton:
[tinytankphp/tinytank](https://github.com/tinytankphp/tinytank).**

📖 **Documentation:** https://docs.tinytank.dev/

## Installation

Sites install it from [Packagist](https://packagist.org/packages/tinytank/framework):

```json
"require": { "tinytank/framework": "^0.1@dev" }
```

The skeleton already contains this. Update a site with `composer update tinytank/framework`: with
`"prefer-stable": true` (also in the skeleton) you get the latest tagged release. TinyTank is in
beta: see [CHANGELOG.md](CHANGELOG.md) before updating to a new minor version.

## What's inside

```
src/                 Kernel, Router, Controller, Datastar, Vite, Site, Translations, Cache, Container
src/Blog/            the Markdown blog: compiler, post SEO, feed, asset serving
src/Seo/             page metadata, sitemap, robots.txt
src/Console/         bin/console: deploy, cache:clear, command discovery
src/Forms/           forms: validation, spam checks (all local), sending with Symfony Mailer
src/Images/          responsive images (AVIF/WebP, srcset) and originals without metadata
src/Log/             the log (Monolog): daily files, email alerts, nothing about visitors
src/Cdn/             caching pages at a CDN (opt-in) and purging Cloudflare, Bunny or any CDN
src/Pages/, src/Collections/, src/Content/   content pages, data collections, content components and embeds
src/Testing/         KernelTestCase, the base class for app tests
resources/vite/      TinyTank's Vite plugin
resources/js/        the Datastar client and the 'tinytank' browser helpers
resources/templates/ default templates: content components (youtube, vimeo), overridable by sites
tests/               the framework's test suite, with a fixture project and content; tests/js: Vitest
```

## Development

```sh
composer update
composer test        # PHPUnit
composer analyse     # PHPStan, level 8
npm install && npm test   # Vitest: the browser helpers in resources/js (Datastar, persist, theme…)
```

The skeleton's Playwright tests then check the whole site in a real browser.

To work on the framework inside a site, clone this repository into the site's `packages/tinytank/`
(gitignored) and run `composer update tinytank/framework`: Composer then symlinks your clone instead
of installing from Packagist. See the skeleton's `CONTRIBUTING.md`.

## Third-party code

`resources/js/datastar.js` is the [Datastar](https://github.com/starfederation/datastar) client
(v1.0.2), MIT License, Copyright © Star Federation: see `resources/js/datastar.LICENSE.md`. Its
license header is kept in sites' built JavaScript (`comments.legal` in the Vite plugin). Everything
else comes through Composer with its own license.

## License

TinyTank is released under the [MIT License](LICENSE), Copyright (c) 2026 lab909. To report a
security problem, see [SECURITY.md](SECURITY.md).
