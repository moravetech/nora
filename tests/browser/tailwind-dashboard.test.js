'use strict';

const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const { setTimeout: delay } = require('node:timers/promises');
const test = require('node:test');
const { chromium } = require('playwright');

const repositoryRoot = path.resolve(__dirname, '../..');

test('Tailwind dashboard serves compiled CSS and supports reactive forms, modal, tabs, and dark mode', async () => {
    const port = await availablePort();
    const publicDirectory = path.join(repositoryRoot, 'examples', 'nora-tailwind-dashboard', 'public');
    let browser;
    let server;
    let serverOutput = '';

    try {
        server = spawn(
            process.env.PHP_BINARY || 'php',
            ['-S', `127.0.0.1:${port}`, '-t', publicDirectory, path.join(publicDirectory, 'router.php')],
            { cwd: repositoryRoot, stdio: ['ignore', 'pipe', 'pipe'] },
        );
        server.stdout.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        server.stderr.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        const url = `http://127.0.0.1:${port}/`;
        await waitForServer(url, server, () => serverOutput);

        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();
        const actionResponses = [];
        const failedAssets = [];
        page.on('response', response => {
            if (response.url().endsWith('/_nora/action')) {
                actionResponses.push(response.status());
            }
            if (
                (response.url().endsWith('/assets/app.css') || response.url().endsWith('/_nora/runtime.js'))
                && response.status() >= 400
            ) {
                failedAssets.push([response.url(), response.status()]);
            }
        });
        page.on('console', message => {
            if (message.type() === 'error') {
                if (/status of 422\b/.test(message.text())) {
                    return;
                }
                serverOutput += `\nBrowser console: ${message.text()}`;
            }
        });

        const response = await page.goto(url, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200, serverOutput);
        assert.equal(await page.title(), 'Nora + Tailwind Dashboard');
        assert.equal(await page.locator('[data-nora-root]').count(), 1);
        assert.equal(await page.locator('link[href="/assets/app.css"]').count(), 1);
        assert.equal(await page.locator('link[href="/_nora/ui.css"]').count(), 0);
        const sidebar = page.locator('aside[aria-label="Main navigation"]');
        assert.equal(await sidebar.isVisible(), true);
        assert.equal(await page.getByRole('navigation', { name: 'Workspace' }).isVisible(), true);
        assert.deepEqual(failedAssets, []);

        const root = page.locator('[data-nora-root]');
        await page.getByRole('button', { name: 'Refresh metrics' }).click();
        await page.waitForFunction(() =>
            document.querySelector('[aria-label="Interface states"] article:nth-child(3) .text-2xl')?.textContent === '1'
        );
        await page.getByRole('button', { name: 'Dark mode' }).click();
        await page.waitForFunction(() => document.querySelector('[data-nora-root] .dark') !== null);
        assert.equal(await root.locator('.dark').count(), 1);

        const profileForm = page.locator('form[data-nora-action="save-profile"]');
        await profileForm.getByLabel('Display name').fill('A');
        await profileForm.getByLabel('Email address').fill('not-an-email');
        await profileForm.getByRole('button', { name: 'Save changes' }).click();
        await page.waitForFunction(() => document.querySelector('[data-nora-field="name"][aria-invalid="true"]'));
        assert.equal(await profileForm.getAttribute('data-nora-state'), 'invalid');

        await profileForm.getByLabel('Display name').fill('Amina El Idrissi');
        await profileForm.getByLabel('Email address').fill('amina@example.test');
        await profileForm.getByRole('button', { name: 'Save changes' }).click();
        await page.waitForFunction(() => document.querySelector('[data-nora-field="name"][aria-invalid="true"]') === null);
        assert.equal(await page.getByRole('status').filter({ hasText: 'Your profile is up to date.' }).count(), 1);

        const weekTab = page.getByRole('tab', { name: 'This week' });
        const monthTab = page.getByRole('tab', { name: 'This month' });
        await weekTab.press('ArrowRight');
        assert.equal(await monthTab.getAttribute('aria-selected'), 'true');
        assert.equal(await page.getByRole('tabpanel', { name: 'This month' }).isVisible(), true);

        const dialog = page.getByRole('dialog', { name: 'Invite a person' });
        await page.getByRole('button', { name: 'Invite person' }).click();
        assert.equal(await dialog.isVisible(), true);
        await dialog.getByLabel('Full name').fill('Nadia Zahra');
        await dialog.getByLabel('Email address').fill('nadia@example.test');
        await dialog.getByLabel('Role').selectOption('Editor');
        await dialog.getByRole('button', { name: 'Send invite' }).click();
        await page.waitForFunction(() => document.querySelector('#invite-modal')?.open === false);
        assert.equal(await page.getByText('Nadia Zahra', { exact: true }).count(), 1);

        const search = page.getByRole('searchbox', { name: 'Search people' });
        await search.fill('no matching person');
        await search.press('Enter');
        await page.waitForFunction(() => document.body.textContent.includes('No people found'));
        assert.deepEqual(actionResponses, [200, 200, 422, 200, 200, 200]);
        await page.setViewportSize({ width: 375, height: 812 });
        assert.equal(await sidebar.isVisible(), false);
        assert.equal(await page.getByRole('navigation', { name: 'Mobile workspace' }).isVisible(), true);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        assert.deepEqual(failedAssets, []);
        assert.doesNotMatch(serverOutput, /Browser console:|Warning:|Fatal error|Uncaught/);
    } finally {
        if (browser) {
            await browser.close();
        }
        if (server && server.exitCode === null) {
            server.kill();
            await Promise.race([
                new Promise(resolve => server.once('exit', resolve)),
                delay(3000),
            ]);
        }
    }
});

async function availablePort() {
    const listener = net.createServer();
    await new Promise((resolve, reject) => {
        listener.once('error', reject);
        listener.listen(0, '127.0.0.1', resolve);
    });
    const port = listener.address().port;
    await new Promise((resolve, reject) => listener.close(error => error ? reject(error) : resolve()));

    return port;
}

async function waitForServer(url, server, output) {
    let lastResponse = 'No HTTP response was received.';
    for (let attempt = 0; attempt < 50; attempt++) {
        if (server.exitCode !== null) {
            throw new Error(`PHP development server exited early:\n${output()}`);
        }
        try {
            const status = await new Promise((resolve, reject) => {
                const request = http.get(url, response => {
                    response.resume();
                    response.once('end', () => resolve(response.statusCode));
                });
                request.setTimeout(1000, () => request.destroy(new Error('HTTP readiness check timed out.')));
                request.once('error', reject);
            });
            if (status === 200) {
                return;
            }
            lastResponse = `HTTP ${status}`;
        } catch (error) {
            lastResponse = error instanceof Error ? error.message : String(error);
        }
        await delay(100);
    }
    throw new Error(`PHP development server did not become ready (${lastResponse}):\n${output()}`);
}
