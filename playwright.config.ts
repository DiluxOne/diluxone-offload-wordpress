import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests against the wp-env DEV site (port 8888).
 *
 * The dev site mounts the repo root, so what runs here is the working tree,
 * not the built dist. The plugin folder in that container is the repo name.
 *
 * Credentials are wp-env's defaults. Override with WP_BASE_URL / WP_USER /
 * WP_PASS to point the suite at another install.
 */
export default defineConfig({
	testDir: './tests/E2E',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['list']] : [['list']],
	outputDir: 'build/e2e-results',
	use: {
		baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8888',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /global\.setup\.ts/,
		},
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				storageState: 'build/e2e-auth.json',
			},
			dependencies: ['setup'],
		},
	],
});
