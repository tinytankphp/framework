# Changelog

All notable changes to `tinytank/framework`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/): while TinyTank is 0.x, a new minor version (0.2.0) may
change behaviour, and its notes say what to update in your site.

## [Unreleased]

### Added

- **A default theme** other than the system's: `'theme' => ['default' => 'dark']` (or `'light'`) in
  `config/app.php`. Visitors without a saved choice get it from the first paint; the inline script's
  CSP hash follows. Keep the layout's signal default the same (`data-signals="{_theme: 'dark'}"`).
- **`site.path`**: the current page's path without the language prefix, for menus that mark the
  current section (`site.path starts with '/projects'`).

### Fixed

- **Small font files are no longer inlined as `data:` URLs** by Vite (some subsets weigh under its
  4 KB limit), which the Content Security Policy blocked (`font-src 'self'`): the browser logged a
  violation and the subset didn't load. Found with Orbitron on lab909.dev. Rebuild your assets.

## [0.1.4] - 2026-10-18

No framework changes: the skeleton and the docs, after a test with a coding agent starting a site
from the docs alone.

### Skeleton

- **`AGENTS.md`** (and a `CLAUDE.md` pointing to it): how a TinyTank site works, where things go,
  the rules that trip people up, the commands. Sites keep it. For an existing site, copy it.
- **`public/index.php` serves files under PHP's built-in server**, so
  `php -S localhost:8000 -t public public/index.php` delivers the built CSS and JS. For an existing
  site, copy the `cli-server` block at the top of the skeleton's `public/index.php`.
- **`datastarUrl()`** in `tests/AppTestCase.php` finds actions of any method (`@post`…), or of one.

### Docs

- `llms.txt`, `llms-full.txt` and a Markdown version of every page, for LLMs and coding agents.
- "Your first page" rewritten: a content page, a page with a controller, browser and server
  interactivity. The starting checklist lists which tests check each starter example, and how to
  make a single-language site.

## [0.1.3] - 2026-10-18

No framework changes: the skeleton and the docs.

### Skeleton

- **`composer create-project` prepares the site**: `bin/create-site.php` runs right after it and
  names the site after its folder (`composer.json`, `package.json`, the DDEV project), creates `.env`
  with a fresh `APP_SECRET`, lets git track `composer.lock`, points CI at it, adds Composer to
  Dependabot, writes the site's README, and removes TinyTank's own maintainer files. With GitHub's
  template button, run `php bin/create-site.php` once.
- The docs have a **Starting a site** checklist for what's left: identity, starter content (the style
  guide included), contact and privacy, licenses.

## [0.1.2] - 2026-10-18

### Fixed

- **Markdown images that aren't photos of the folder** (an image from another site, a file from
  `public/` such as `/favicon.svg`, or an SVG in the folder) made the page fail with "Typed property
  …ImageRenderer::$config must not be accessed before initialization". They render as plain `<img>`
  again. Found by the first real site's style guide.

### Skeleton

- **A style guide page**, `content/pages/style-guide/` (unlisted, at `/style-guide`): every element a
  post or page can contain, for designing a site, and checked by the skeleton's tests, so a broken
  element fails CI. For an existing site, copy the folder from the skeleton.
- Code block labels no longer overlap long lines (`padding-top` in the "Code blocks" CSS).

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

[Unreleased]: https://github.com/tinytankphp/framework/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/tinytankphp/framework/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/tinytankphp/framework/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/tinytankphp/framework/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/tinytankphp/framework/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/tinytankphp/framework/releases/tag/v0.1.0
