# Changelog

All notable changes to `tinytank/framework`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/): while TinyTank is 0.x, a new minor version (0.2.0) may
change behaviour, and its notes say what to update in your site.

## [Unreleased]

## [0.1.1] - 2026-10-18

Found while building the first real TinyTank site (lab909.dev).

### Added

- **Syntax highlighting for code blocks** (```php, ```twig…), when content compiles, with
  [tempest/highlight](https://github.com/tempestphp/highlight): no JavaScript, nothing loaded from
  elsewhere. Blocks are `<pre data-lang="php"><code class="language-php">` with `hl-*` classes for
  the colours; the skeleton's `resources/css/app.css` styles them. To get them in your site, copy the
  "Code blocks" rules from the skeleton's `app.css`. Unknown languages stay plain text.
- **Unlisted content pages**: `unlisted: true` in a page's front matter keeps it reachable by its
  URL but out of search engines (`noindex`), the sitemap, and menus that ask for
  `where('unlisted', false)`. For a style guide, a thank-you page, a preview. To skip them in your
  menus, add `.where('unlisted', false)` to their queries, as in the skeleton's `templates/_layout.twig`.

## [0.1.0] - 2026-10-15

The first tagged release. TinyTank is in beta: the API may still change between minor versions.

### Included

- **Kernel and routing**: Symfony Routing compiled for Opcache, controllers, a small PSR-11 container,
  stateless CSRF protection (Fetch Metadata / Origin), languages with URL prefixes and translated slugs.
- **Content**: a Markdown blog (drafts, translations, tags, feed, pagination), nested content pages,
  typed data collections (YAML or Markdown, optional JSON export), an immutable query API
  (`posts()`, `pages()`, `collection()`), content components (`::name{…}`) with privacy-friendly
  YouTube and Vimeo embeds (click to load, posters served locally).
- **Templates and frontend**: Twig, Datastar (signed template actions, server-sent events), Vite and
  Tailwind integration, the `tinytank` browser helpers (`ready`, `persist`, `publicConfig`, `theme`),
  dark/light/system theme without a flash, self-hosted fonts and icons.
- **SEO**: titles, descriptions, Open Graph, JSON-LD, hreflang alternates, sitemap, robots.txt, Atom feed.
- **Images**: AVIF and WebP versions at several widths with `srcset`, presets (ratio, crop, fit,
  letterbox, stretch), metadata (EXIF, GPS) removed from every published image.
- **Forms**: definitions in `config/forms.php`, validation in the page's language, sending with
  Symfony Mailer, spam checks that keep everything on your server (honeypot, timing, links, rate limit).
- **Security and privacy**: a strict Content Security Policy (per-page additions for embeds), safe
  Markdown, a log that records nothing about visitors (no IP addresses, query strings or form contents),
  third-party licence notices kept in built assets.
- **Operations**: `deploy` (compile everything, publish media, refresh Opcache, purge the CDN),
  `cache:clear`, logging with Monolog (daily files, optional email alerts), opt-in CDN caching with
  purging for Cloudflare, Bunny or any CDN (`cdn:purge`).
- **Testing**: `KernelTestCase` for app tests, with throw-away directories and the log.

### Changed, if your site was created from the development branch

- **Datastar template actions are bound to their HTTP method.** The method is part of the signed
  config: a `datastar.post()` URL answers only POST (other methods get a 405). URLs signed before
  this change are refused with a 400: pages open in a browser need a reload after the deploy, and
  `deploy` purges a CDN.
- **Dates in front matter and collections must be real days** in `YYYY-MM-DD` form: `2026-02-30`
  is now an error instead of March 2. Run `bin/console deploy` (or open the site with `APP_DEBUG=1`)
  to see any file that needs fixing.
- **Spam checks are validated after `config/bootstrap.php`**, so checks added there with
  `addSpamCheck()` can be used in `config/forms.php`.
- **Renamed from Starlite to TinyTank.** The package is now `tinytank/framework` on Packagist
  (it was `starlite/framework`, installed from GitHub). In your site:
  - `composer.json`: replace `"starlite/framework": "^1.0@dev"` with `"tinytank/framework": "^0.1@dev"`,
    remove the `vcs` repository pointing at GitHub, then `composer update`;
  - PHP: the namespace is `TinyTank\` (was `Starlite\`): `use TinyTank\Kernel;`…;
  - JavaScript: import from `'tinytank'` (was `'starlite'`), and in `vite.config.js` the plugin is
    `vendor/tinytank/framework/resources/vite/tinytank.js`;
  - Twig: the framework's templates are `@tinytank/…` (was `@starlite/…`);
  - browser storage keys changed (`tinytank`, `tinytank-theme`): visitors' saved theme and persisted
    signals start from the defaults once.
- **Skeleton files to compare** with [tinytankphp/tinytank](https://github.com/tinytankphp/tinytank), if you
  want the same: `config/app.php` (`log` and `cdn` sections), `src/Controller/PageController.php`
  (`cdn: false` support), `config/routes.php` (blog page numbers up to 6 digits), `.env.example`.

[Unreleased]: https://github.com/tinytankphp/framework/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/tinytankphp/framework/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/tinytankphp/framework/releases/tag/v0.1.0
