# Changelog

All notable changes to `tinytank/framework`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/): while TinyTank is 0.x, a new minor version (0.2.0) may
change behaviour, and its notes say what to update in your site.

## [Unreleased]

### Added

- **A redirect map**: `config/redirects.php` (optional), old path => new path or
  `['to' => …, 'status' => 302]`, to this site or another. Answered first, before languages and
  routes, keeping the query string. Mistakes (a path without `/`, a chain, an unknown status) stop
  the site with a message; `bin/console check` (so `deploy`) reports an old path that still has a
  page and a target that doesn't answer; static builds write the map into the host's redirects.
  `$app->redirects`.
- **Signals read with their type**: `signal('q')`, `signal_int('page', 1, min: 1, max: 1000)`,
  `signal_bool()`, `signal_enum('sort', […])` in Twig, `$app->signals()` (and `$this->signals()` in
  controllers) with `string()`, `int()`, `bool()`, `enum()`, `has()`, `all()` in PHP. Whatever a
  browser sends, they return the type asked for, or the default. The `signals` variable is unchanged.
- **`TinyTank\HttpError`**: `throw new HttpError(403, 'Not allowed.')` from a handler answers with
  that status's error page.
- `KernelTestCase::request()` passes `Content-Type` and `Content-Length` as web servers do, so a
  test can send JSON (`['Content-Type' => 'application/json']`).
- **`static:build`, `static:preview` and `static:publish`**: aliases of `build:static`,
  `preview:static` and `publish:static`, so the three group together in `bin/console list`, like
  `cache:clear` and `site:clean`.
- **`$app->noStore()`**: the current response is for one visitor (an account page, a basket):
  `Cache-Control: no-store, private` and no ETag, never kept by a browser or a CDN. Pages with a
  form already did this by themselves.

### Changed

- **Static forms answer in place when Datastar sends them**: the Worker replies with the form's
  "sent" state (taken by the build from the form page's sent version, by the form's `id`), or with
  the mistakes as the `_forms.<form>.errors.<field>` and `.errors._form` signals, so nothing typed
  is lost. `form_spam()` creates those signals in static builds (put it first in the form); a
  Datastar post to `/forms/<name>` no longer counts as needing the server. Without JavaScript,
  nothing changes.
- **Bad Datastar requests answer 400** (signals that aren't a JSON object) **or 413** (more than
  64 KB), instead of rendering with no signals.
- **A response that sets a cookie is never public**: it gets `no-store, private` and no ETag,
  whatever its handler or the CDN settings say (TinyTank has no sessions, so a cookie means the
  response is for that visitor). Responses without cookies are cached as before.

### Skeleton

- The contact form is sent with Datastar on static sites too, and shows the host's answer in place
  (`templates/_forms/contact.twig`: the `data-on:submit` for both, `form_spam()` first, the
  `site.static` error elements). For an existing site, copy those changes.
- `config/redirects.php`, empty, with examples. Optional: a site without it has no redirects.
- `playwright.config.js`: the test server clears `var/state/forms` before starting. Since 0.1.8 the
  forms' rate limits live in `var/state/`, which `cache:clear` doesn't empty, so repeated local runs
  of the browser tests hit the contact form's limit (5 an hour). For an existing site, add
  `rm -rf var/state/forms &&` after `php bin/console cache:clear &&` in `webServer.command`.
- The blog search and the Datastar demo read their signals with `signal()` and `signal_int()`, so a
  request with odd values gets an empty search instead of an error. For an existing site, the same
  change in `templates/_partials/blog-results.twig`, `blog-more.twig` and `search.twig`.

## [0.1.8] - 2026-10-21

Deploy safety. `deploy` now checks the site first (`bin/console check`), builds aside and switches
last, so a failure leaves the live site as it was, and keeps the previous build for
`deploy --rollback`. To check in an existing site:

- a deploy now **stops without a Vite build** when the site has a `vite.config.js` (run
  `npm run build` first, or `deploy --assets`), and when `check` finds an error (a page whose
  template is missing, an `APP_SECRET` shorter than 32 characters…): run `bin/console check` once;
- app deploy steps without `before`/`after` now run before `switch`;
- the forms' rate limits move to `var/state/` (they restart from zero once);
- in the skeleton: `public/index.php` and `.gitignore` (see Skeleton).

### Added

- **`bin/console check`**: a read-only preflight (compiled into a temporary folder): PHP and its
  extensions, GD or Imagick, `APP_SECRET` and `APP_URL`, writable folders, content, every template
  (and each page's template and form), pages hidden by routes, the Vite build and the entry points
  templates name. `--json` for CI. `deploy` runs it first, as its `check` step.
- **`deploy --rollback`** puts the previous build back (its cache and published files), then
  refreshes Opcache and purges the CDN; a second rollback undoes the first.
- **`Kernel::serve($root)`** for `public/index.php`: a site that can't start (a mistake in `config/`,
  a missing extension) shows a plain 500 page and logs why in PHP's log, instead of PHP's own error;
  with `APP_DEBUG=1` the error shows as before.
- `state_dir` (default `var/state/`): what the site keeps across deploys.

### Changed

- **`deploy` builds first and switches last**: the new cache and the published files are built in
  `var/deploy/next/` while the site keeps answering from the current ones, and the new `switch` step
  moves them in only when every step before it succeeded. A failing step leaves the live site as it
  was; the build it replaced is kept in `var/deploy/prev/`. During a deploy, `$app->cacheDir` is the
  new cache's folder, and app steps run before `switch` by default (they ran before `opcache`).
- **A missing Vite manifest stops the deploy** (every page needs it), unless the site has no
  `vite.config.js`. It was a warning.
- **The forms' rate limits are in `var/state/forms/`**, kept across deploys (every deploy reset them
  from `var/cache/`).
- **Images load their library on first use**: a server without GD or Imagick boots and serves images
  as they are; publishing them still needs one (their metadata must go).
- `cache:clear` also empties `var/deploy/`.

### Skeleton

- `public/index.php` calls `TinyTank\Kernel::serve(dirname(__DIR__))`. For an existing site, replace
  its last line (`Kernel::boot(…)->run()`) with that.
- `.gitignore`: `/var/state/` and `/var/deploy/`. For an existing site, add both.

## [0.1.7] - 2026-10-20

TinyTank as a static site generator: `build:static` writes the whole site as plain files,
`preview:static` serves them as the host will, and `publish:static` puts them on Cloudflare Workers,
with forms that keep working (sent by a small Worker, through Cloudflare or your own mail server).
Nothing changes for a site that stays on PHP, and nothing needs updating in an existing site. To
build one statically, see the docs' Static Site Generator section; the skeleton's changes below
(the contact form, the blog search, the DDEV port) say what to copy.

### Added

- **Static builds**: `bin/console build:static` renders the whole site into plain files (`deploy/` by
  default) for a static host, while development stays the PHP site. Every page is found by
  following links from the home page, the sitemap, the feed and robots.txt, and written where the
  host serves it at the PHP site's URL, with `public/`, the published media and `404.html`. It
  reports by page what needs the server (Datastar server actions, forms the host doesn't answer)
  and stops on broken links; a plain Datastar GET of a page of the site is built like a link (the
  file answers it). `site.static` tells templates. Config: `static.out`, `static.base_url`,
  `static.paths`, `static.host`, `static.name`. See the docs' Static Site Generator section.
- **Hosts for static builds**, behind `TinyTank\Static\HostAdapter` (where each page goes, and the
  host's own files): `cloudflare`, the default, for Cloudflare Workers with static assets
  (`<path>.html`, served at the PHP site's URLs; `_headers` with one site-wide Content Security
  Policy, pages that add a script source getting their own rule; `_redirects`; `wrangler.jsonc` for
  `npx wrangler deploy`, on `static.domains` when set; checked against Cloudflare's limits), and
  `none` (redirects as HTML pages).
- **`bin/console preview:static`** serves the static build locally, as its host will: Cloudflare's
  local server (`wrangler dev`: headers, redirects, the forms' Worker with emails written to files),
  or PHP's built-in server with `--php` (pages, files, redirects; no Node needed).
- **A route can stay out of the sitemap**: `sitemap: false` in `$app->get()` / `$app->route()`, for
  a GET page that isn't one on its own (a fragment Datastar loads).
- **Forms on static sites**: a form with `'static' => ['from' => …]` in `config/forms.php` is
  answered by the host, sending to `static.to` (default: the form's `to`, which in development is
  often DDEV's Mailpit). On Cloudflare, the build adds a Worker (only for `/forms/<name>`) that
  checks it like the PHP site (required fields, lengths, email, choices, honeypot, links limit, the
  same messages in the page's language) and emails it through Email Routing's `send_email`
  binding, locked to the form's recipients; the visitor lands on `<page>/sent`. Templates use
  `form_action('contact', url)`; `form_spam()` prints what works without the server. Everything
  above was checked with Cloudflare's local server (`wrangler dev`), the form in a real browser.
- **Forms on static sites can send through your own mail server**: `'send' => 'smtp'` in the
  form's `static` (Cloudflare's email binding needs a paid Workers plan). The Worker speaks SMTP
  over `cloudflare:sockets` (port 465 TLS, 587 STARTTLS, AUTH PLAIN or LOGIN), with `SMTP_HOST`,
  `SMTP_PORT`, `SMTP_USER` and `SMTP_PASSWORD` as its secrets.
- **`bin/console publish:static`** builds the static site and publishes it on Cloudflare
  (`wrangler deploy`) with `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID` from `.env`, no browser
  login; forms sent over SMTP get the `SMTP_*` keys from `.env` as the Worker's secrets, after the
  deploy (`wrangler secret bulk`, on its input: never in a file). `--no-build`, `--dry-run`. It
  warns about a `$` outside single quotes in those `.env` values (read as a variable, the password
  arrives changed), and names the API token's permissions when Cloudflare refuses it.

### Skeleton

- **The static preview's port in DDEV**: 4173, at `https://<project>.ddev.site:4173` (8080 is often
  taken on the host, which stops DDEV's router). For an existing
  site, add the `static` entry under `web_extra_exposed_ports` in `.ddev/config.yaml` and
  `ddev restart`.
- **The blog search works on static sites**: `/blog/search` (route `blog_search`,
  `templates/blog/search.twig`, `noindex`) lists every post with its words and tags; in a static
  build the search box and tags load it on first use and filter in the browser. For an existing
  site, copy the route (with `sitemap: false`), `BlogController::search()`,
  `templates/blog/search.twig` and the `filter` part of `templates/blog/index.twig`.
- **The contact form works on static sites**: `templates/_forms/contact.twig` posts with
  `form_action()` and leaves out Datastar's submit in a static build. For an existing site, copy
  its `<form …>` tag. `deploy/` and `var/static-cache/` in `.gitignore`; a commented `static`
  example in `config/app.php`.

## [0.1.6] - 2026-10-19

What the lab909.dev retrospective asked for. One change to check in an existing site: collections
now refuse fields named `assets` or `reading_minutes` (TinyTank sets them). For collection items
with their own files, add the development route to `config/routes.php` (see Added). The skeleton's
changes are listed below, each with what to copy.

### Added

- **Collection items with their own files**: an item can be a folder, `content/<collection>/<slug>/`
  with `index.md` (or `index.yaml`, translations `index.<language>.md`) and its images and files next
  to it, like a post. A `url` field can name one of them (`image: cover.jpg`), relative links and
  images in the body point at them, and images get responsive AVIF/WebP versions, in the body and
  through `image()`. `deploy` publishes them to `public/media/collections/`. **In an existing site**,
  add the development route to `config/routes.php` (see the skeleton's, or Data collections in the
  docs): `$app->get(Collections::ASSET_URL . '/{file}', CollectionAssetController::class, 'collection_asset', ['file' => '.+']);`

- **A parent page sets its children's template**: `children: {template: pages/review.twig}` in its
  front matter, for its direct child pages that don't name one.

- **`except()` and `neighbours()` in queries**: `posts().except(post)` for every post but this one
  (an item, a slug, a page's path, or a list); `query.neighbours(item, wrap: true)` for its previous
  and next items in the query's order, for "previous / next" links.

- **Reading time for pages and collection items**: `reading_minutes`, as posts have.

- **`bin/console site:clean`** removes a site's starter examples: the files listed in `.examples` and
  the blocks between `example:start` and `example:end` lines in its config, templates and tests. It
  lists everything first and asks (`--dry-run`, `--force`). `deploy` warns while `.examples` exists.

### Changed

- **`assets` and `reading_minutes` are reserved in collections** (TinyTank sets them): a field with
  either name is now refused, with a message. Rename it if you have one.
- **Error messages suggest the fix**: a typo in a query field, a collection field or a page's front
  matter gets "Did you mean "summary"?"; `'?metrics' => 'array'` says the `?` goes on the type
  (`'metrics' => '?array'`); a camelCase field name gets its snake_case form; a missing collection
  field says how to make it optional.

### Fixed

- **`content()` stays HTML in a variable**: `{% set body = content(post) %}{{ body }}` printed the
  HTML escaped, as text, because only a direct `{{ content(…) }}` was marked safe. It now returns
  Twig's `Markup`. Found twice building lab909.dev.

### Skeleton

- **The examples come off in one step**: `create-project` asks whether to keep them (the Datastar
  demos, the FAQ, the sample posts, the Italian translation); `php bin/console site:clean` removes
  them later, with their tests, leaving an English site that passes its tests (a test cleans a copy
  of the skeleton and runs the copy's suite). The home page is now a neutral template including
  `_examples/home.twig`; the image, video and no-JavaScript browser tests use the style guide.
- **A simpler blog pager**: numbered pages, always visible; "Load more" only for search and tag
  results, which have no page URLs. For an existing site, copy `templates/_partials/blog-pager.twig`
  (its tests: `tests/BlogPagesTest.php`).
- **Gentler reduced motion** (`resources/css/app.css`): visitors who ask for less motion still get
  colour and opacity fades; only movement and animations stop. An effect the visitor switched on
  knowingly can keep moving with the class `motion-allowed`. Put hover movement behind
  `motion-safe:`. For an existing site, copy the `prefers-reduced-motion` block and
  `tests/e2e/accessibility.spec.js`.
- The related-posts component uses `except()`; the route for collection item files.
- **`site()` in `tests/AppTestCase.php`**: the site with its real `content/`, instead of repeating
  `overrides: ['content_dir' => self::ROOT . '/content']`. For an existing site, copy `AppTestCase.php`.

## [0.1.5] - 2026-10-19

What building lab909.dev needed. Nothing to change in an existing site; rebuild its assets
(`npm run build`) for the font fix.

### Added

- **A default theme** other than the system's: `'theme' => ['default' => 'dark']` (or `'light'`) in
  `config/app.php`. Visitors without a saved choice get it from the first paint; the inline script's
  CSP hash follows. Keep the layout's signal default the same (`data-signals="{_theme: 'dark'}"`).
- **Global values**: `config/globals.php` returns an array (nested as deep as you like) that every
  template reads as `globals`, like Craft's globals: `{{ globals.socials.github }}`. Optional; PHP
  reads it as `$app->globals`. Public values only, never secrets.
- **Paths inside a field in queries**: `where()`, `orderBy()` and `countBy()` accept
  `pages().where('data.featured', true).orderBy('data.date desc')`, or a collection's array field
  (`address.city`). Pages' `data` is now a query field (it wasn't, so pages couldn't be filtered or
  sorted by it at all).
- **`site.path`**: the current page's path without the language prefix, for menus that mark the
  current section (`site.path starts with '/projects'`).

### Fixed

- **Small font files are no longer inlined as `data:` URLs** by Vite (some subsets weigh under its
  4 KB limit), which the Content Security Policy blocked (`font-src 'self'`): the browser logged a
  violation and the subset didn't load. Found with Orbitron on lab909.dev. Rebuild your assets.

### Skeleton

- **`config/globals.php`**, empty, with a commented example. For an existing site, create it when
  you need it.
- **`config/app.php`**: a commented `theme` example (`'default' => 'system'`).
- **`AGENTS.md`**: global values in "Where things go", and "ARIA states are strings"
  (`data-attr:aria-expanded="String($open)"`) in the rules that trip people up. For an existing
  site, copy the two lines.

### Docs

- Global values (Templates), paths inside a field (Querying content), a default theme and a two-way
  light/dark switch (Theme), `site.path`, and "ARIA states are strings" (Datastar).

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

[Unreleased]: https://github.com/tinytankphp/framework/compare/v0.1.8...HEAD
[0.1.8]: https://github.com/tinytankphp/framework/compare/v0.1.7...v0.1.8
[0.1.7]: https://github.com/tinytankphp/framework/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/tinytankphp/framework/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/tinytankphp/framework/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/tinytankphp/framework/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/tinytankphp/framework/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/tinytankphp/framework/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/tinytankphp/framework/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/tinytankphp/framework/releases/tag/v0.1.0
