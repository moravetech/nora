# Tutorial: Your First Nora Application

This tutorial runs the generated counter page end to end. It demonstrates
server rendering, PHP-owned state, explicit actions, and the framework-owned
browser runtime. The generated app uses no application-authored JavaScript.

## Requirements

- PHP 8.2 or newer
- Composer 2
- The Nora source checkout

## Create and install the app

From the Nora checkout, run:

```powershell
php bin\nora new hello-nora
cd hello-nora
composer install
npm install
copy .env.example .env
composer test
npm run nora:assets:build
```

In a POSIX shell, the equivalent CLI path is `php bin/nora new hello-nora`;
copy the environment example with `cp .env.example .env`.

The generated project installs Nora from the official Git repository's
`dev-main` branch, so it does not depend on the local framework checkout.
That development branch is mutable; Nora does not yet publish a tagged
package release. Before production use, review the release checklist and
select an approved stable version when one is available. After `composer
install`, run Nora's installed binary with
`composer exec nora -- <command>` from the app root; this keeps the command's
working directory on the application rather than the framework checkout.

## Inspect the PHP component

The generated `src/routes.php` registers `HomeController` at `/welcome` and
mounts a `CounterPage` at `/`. The page links to the locally compiled
`public/assets/app.css` stylesheet.
`src/Reactive/CounterPage.php` extends `Nora\Reactive\Component`. Its
`initialState()` returns server-owned state, `view(State $state)` composes
escaped markup with `UI`, and `actions()` explicitly allowlists the
`increment` transition:

```php
protected function view(State $state): SafeHtml
{
    return UI::fragment([
        UI::element('h2', [], 'Count: ' . $state->get('count')),
        UI::actionButton('increment', 'Increment', ['amount' => 1]),
    ]);
}
```

The generated page uses `UI::each()` for two keyed counter sections. Both read
the same page state, so one authorized action updates both outputs. `UI`
escapes text and attributes, rejects event-handler/style attributes and unsafe
URL schemes, and creates protocol-ready buttons without hand-written HTML.
`State::computed()` derives a render-time value from the immutable state
snapshot. The framework adds its document-level runtime script. Tailwind CSS
v4 is compiled locally through its official CLI; no CDN, runtime compiler,
custom JavaScript, or per-component mounting code is required. The generated
input scans `src/`, which includes PHP and `.nora` templates without scanning
dependencies or generated output. `HomeController` renders
`src/Views/home.nora` through `TemplateRenderer`, which escapes interpolated
values. App classes use Composer's `App\` to `src/` PSR-4 mapping; routes are
registered explicitly, with no class or route discovery. Use
`npm run nora:assets:dev` and `npm run nora:assets:build` for CSS workflows.
The framework's `assets:dev`, `assets:build`, and `assets:check` CLI commands
are available when using a Nora checkout that includes the Tailwind CLI
integration. Add `@source` directives for any other application directories
containing utility classes.

## Run and interact

Start the development server from the generated project:

```powershell
composer exec nora -- serve
```

For active CSS development, start `composer exec nora -- assets:dev` in another
terminal before or alongside the web server. The build output is same-origin;
production deployments should run `assets:build` as part of their release
build and serve the resulting static CSS file.

Open `http://127.0.0.1:8000/`. Click either counter button: both displayed
counts should advance without a document navigation. Enter text into the
unsubmitted note field and click a button; the field value and activated
control focus should remain. Visit `/welcome` to see the generated controller
and view. Stop the development server with `Ctrl+C`.

The generated app performs a real same-origin `POST` to Nora. It sends a
session-bound page handle, an action name, validated arguments, a CSRF token,
and an expected revision. It does not send replacement page state. A stale
revision is rejected instead of replaying the action.

## Add another route

Add a distinct route to `src/routes.php` after mounting the reactive page:

```php
$app->route(
    'GET',
    '/health',
    static fn (\Nora\Http\Request $request, array $parameters): \Nora\Http\Response =>
        \Nora\Http\Response::json(['status' => 'ok'])
);
```

Routes are matched in registration order. Register specific routes before
overlapping parameterized routes.

Generate additional application source files from the app root:

```powershell
composer exec nora -- make:controller Profile
composer exec nora -- make:middleware RequestLog
composer exec nora -- make:service ProfileReader
composer exec nora -- make:component ProfilePanel
composer exec nora -- make:view profile
composer exec nora -- make:test Profile
```

These commands create classes or templates under `src/`; register routes and
middleware explicitly in `src/routes.php`. `make:component` creates a Nora
reactive page component, not an automatically discovered view component.
Services are plain classes instantiated by application code; there is no
service container. `make:test` creates a PHPUnit feature test under
`tests/Feature`; the scaffold installs PHPUnit as a development dependency.

## Verify the browser behavior

The repository's Playwright test creates and installs a clean generated
application, serves it with PHP's development server, and checks that the
official runtime updates both components without navigation while preserving
input, focus, and keyed DOM identity:

```powershell
# Run from the Nora checkout
npm ci
npx playwright install chromium
npm run test:browser
```

The PHP unit and integration suites are run separately with
`composer test -- --display-warnings`.

## Prototype limitations

This tutorial demonstrates one server-owned page state with adjacent
components. Independent nested component state, lifecycle hooks, reactive
forms, validation feedback UI, navigation/history, offline behavior,
automatic retries, uploads, and persistent real-time transport are not
implemented. PHP component render methods produce trusted markup; escape
dynamic values with `TemplateRenderer`. The development server is not for
production. See the [Reactive UI Engine specification](../../architecture/REACTIVE_UI_ENGINE.md)
for the design and security boundaries.
