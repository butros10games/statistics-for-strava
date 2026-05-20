# Local setup

The repository uses Docker for the PHP application and `npm` + `package-lock.json` for frontend tooling.

## First-time setup

Run the following commands after cloning your fork:

```bash
git clone git@github.com:your-name/your-fork.git
cd statistics-for-strava
make composer arg="install --no-scripts"
make up
make app-install-node-deps
```

## Common development tasks

The project now exposes the most common frontend and PHP quality tasks through `package.json` and `composer.json`, while keeping the existing `make` targets as Docker-friendly wrappers.

### Frontend assets

Build the production assets:

```bash
npm run build
```

Or, if you prefer to stay inside the Docker workflow:

```bash
make app-build-assets
```

Watch CSS and JavaScript separately during development:

```bash
npm run watch:css
npm run watch:js
```

Docker wrappers are also available when you want the watchers to run in the `php-cli` container:

```bash
make app-watch-assets-css
make app-watch-assets-js
```

If you want an unminified Tailwind build for debugging, generate it explicitly:

```bash
npm run build:css:debug
```

### PHP quality checks

Common PHP workflows are available through Composer scripts:

```bash
composer test
composer test:parallel
composer phpstan
composer cs:check
composer rector:check
```

If you need the Docker-backed wrappers with the existing cache-reset behaviour, the `make` targets are still available.

## Rebuilding generated HTML

Whenever you change templates, translations, or backend code that affects generated pages, rebuild the app HTML files:

```bash
make console arg="app:strava:build-files"
```

## Suggested local workflow

For frontend work:

1. Start the containers with `make up`.
2. Run `npm run watch:css` and `npm run watch:js` (or the Docker wrappers) in separate terminals.
3. Rebuild the generated HTML with `make console arg="app:strava:build-files"` when template or backend changes need a fresh static build.

For backend work:

1. Use the Composer scripts for quick local feedback.
2. Use the `make` targets when you want the exact Docker-backed execution path used elsewhere in the project.