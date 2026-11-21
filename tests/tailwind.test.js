'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repositoryRoot = path.resolve(__dirname, '..');

test('Tailwind v4 production build contains application utilities and scans only declared sources', () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(repositoryRoot, 'package.json'), 'utf8'));
    const stylesheetPath = path.join(repositoryRoot, 'resources', 'css', 'app.css');
    const outputPath = path.join(
        repositoryRoot,
        'examples',
        'nora-tailwind-dashboard',
        'public',
        'assets',
        'app.css',
    );
    const stylesheet = fs.readFileSync(stylesheetPath, 'utf8');
    const productionCss = fs.readFileSync(outputPath, 'utf8');

    assert.match(manifest.devDependencies.tailwindcss, /^\^4\./);
    assert.match(manifest.devDependencies['@tailwindcss/cli'], /^\^4\./);
    assert.match(manifest.scripts['assets:build'], /--minify/);
    assert.match(stylesheet, /@import "tailwindcss" source\(none\)/);
    assert.match(stylesheet, /@source "\.\.\/\.\.\/src"/);
    assert.match(stylesheet, /@source "\.\.\/\.\.\/examples\/nora-tailwind-dashboard\/src"/);
    assert.doesNotMatch(stylesheet, /node_modules|vendor|public\/assets/);
    assert.ok(productionCss.length > 0);
    for (const utility of [
        '.grid-cols-1',
        '.sm\\:grid-cols-2',
        '.hover\\:bg-indigo-700',
        '.dark\\:bg-slate-950',
        '.disabled\\:opacity-50',
        '.aria-busy\\:opacity-60',
    ]) {
        assert.ok(productionCss.includes(utility), `production CSS must include ${utility}`);
    }
    assert.doesNotMatch(productionCss, /--nora-color-primary|\.nora-button--/);
});
