import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    timeout: 120_000,
    expect: { timeout: 20_000 },
    workers: 1,
    reporter: 'list',
    outputDir: '../artifacts/browser',
    use: {
        baseURL: 'http://127.0.0.1:8000',
        channel: 'msedge',
        headless: true,
        viewport: { width: 1440, height: 1060 },
        trace: 'retain-on-failure',
    },
});
