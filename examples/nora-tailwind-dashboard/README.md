# Nora + Tailwind CSS Dashboard

This PHP-first dashboard uses Nora for server rendering, session-owned
reactivity, form validation, actions, and DOM reconciliation. Tailwind CSS v4
provides its visual design; the compiled stylesheet is served locally, with no
CDN or browser-side compiler.

## Run locally

From the Nora repository root:

```powershell
composer install
npm ci
npm run assets:build
php -S 127.0.0.1:8002 -t examples\nora-tailwind-dashboard\public examples\nora-tailwind-dashboard\public\router.php
```

Open [http://127.0.0.1:8002](http://127.0.0.1:8002). Run `npm run assets:dev`
in a second terminal while editing PHP or CSS. Stop both processes with
Ctrl+C.

The demo has responsive navigation, searchable people records, statistics,
profile and create-person forms, server validation, a native modal, keyboard
tabs, loading/error/success feedback, and a reactive dark-mode switch. Its
records are intentionally in-memory session state; it is not a production
application template.

## Tailwind source and production output

`resources/css/app.css` imports the official Tailwind CSS v4 package and
explicitly scans Nora source and this demo's PHP source. Tailwind does not
scan `vendor`, `node_modules`, generated output, or unrelated project
directories. Add an `@source` directive for any additional application
templates that contain utility classes.

`npm run assets:build` generates the minified production CSS at
`examples/nora-tailwind-dashboard/public/assets/app.css`. The app serves that
file as a same-origin static asset and does not request the legacy Nora UI
theme. No runtime compiler, CDN, or frontend framework is required.

The reactive UI APIs remain experimental. Local browser tests are not an
accessibility or security certification.
