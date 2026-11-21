# Upgrading

Nora has no stable release or published versioned compatibility contract yet.
The reactive UI and component APIs are explicitly experimental and unstable.
Review the change log and release notes before updating a source checkout.

## Current source checkout

Update the checkout using the maintainer's normal review process, then run:

```powershell
composer install
composer validate --strict
composer audit
composer test -- --display-warnings
npm ci
npm run test:tailwind
npm run test:browser
```

Do not update production by tracking an unreviewed development branch. Prefer
a maintainer-approved release once one exists.

## Generated applications

Generated applications use the official Nora Git repository with the moving
`dev-main` constraint, so dependency resolution does not rely on the checkout
that generated them. This is not a stable release constraint. When an
approved tagged release is available, update the application's Composer
constraint deliberately, then install dependencies and rebuild Tailwind CSS.

The Tailwind integration uses the official v4 CLI. Existing Tailwind v3
projects can retain their own v3 configuration and scripts; Nora's
`tailwind:init` and `assets:*` commands validate v4 configuration only.
