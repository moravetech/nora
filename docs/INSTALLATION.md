# Installation

## Current distribution status

Nora's Composer package metadata is `morave/nora`, but no Packagist package or
stable release has been verified. A normal `composer require morave/nora` is
not currently an available installation path. Generated projects instead
configure the official Git VCS repository and require the moving `dev-main`
branch; this supports independent installation but is not a stable release.

The package owner must approve and verify the release repository/vendor and
publication workflow before a stable package is advertised. The current
repository's [release checklist](PACKAGE_RELEASE.md) documents that gate.

## Development from a source checkout

For development in this repository:

```powershell
composer install
composer validate --strict
composer test -- --display-warnings
```

The framework requires PHP 8.2 or newer and Composer 2. Database use requires
PDO and the PDO driver for the selected DSN. `pdo_mysql` is optional unless
the application uses MySQL/MariaDB. The current CI matrix covers PHP 8.2 and
8.3; compatibility beyond the tested matrix is not claimed.

## Generated application

From the Nora checkout:

```powershell
php bin\nora new my-app
cd my-app
composer install
npm install
copy .env.example .env
npm run nora:assets:build
composer exec nora -- serve
```

Open `http://127.0.0.1:8000/`. The generated Composer manifest installs
`morave/nora` from `https://github.com/Yasser-devb/nora.git` at `dev-main`,
so it does not depend on the checkout that generated the app. The branch is
mutable and may change incompatibly; no stable package version is available
yet. The generated-project browser acceptance test exercises dependency
installation and app behavior.

The currently reachable upstream branch still declares `ext-pdo_mysql` as a
package requirement. Until that metadata change is available upstream,
generated-app installation requires the PHP MySQL PDO extension even if the
application itself does not use MySQL; it does not require a running MySQL
server for installation.

Run `npm run nora:assets:dev` in another terminal for CSS watch mode. Tailwind
CSS is compiled locally by the official Tailwind v4 CLI; no CDN or runtime
compiler is used. See the [first application tutorial](tutorials/FIRST_APPLICATION.md).

The PHP built-in server is for local development, not production. A
production deployment must configure its web server to serve `public/`,
provide secrets through its environment, and run Composer/npm build steps as
part of its controlled deployment process.
