import { defineConfig, devices } from '@playwright/test';

/**
 * The real-storage suite: every plugin screen driven as a user, on a single
 * site and on a multisite network, against a real Azure storage account.
 *
 * Credentials come from AZURE_E2E_ACCOUNT / AZURE_E2E_KEY (repository
 * secrets in CI, a git-ignored .env.e2e locally). Each run owns one
 * container, created in the setup project and deleted in the teardown.
 *
 * The journeys are stateful and long, so they run serially in one worker
 * and a step that fails stops the rest of its journey.
 */
export default defineConfig( {
	testDir: './tests/E2E-real',
	timeout: 15 * 60 * 1000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],
	outputDir: 'build/real-results',
	globalTeardown: './tests/E2E-real/global.teardown.ts',
	use: {
		// No traces: a trace records every fill() value, and the suite fills
		// the real access key. Both key fields are password inputs, so the
		// screenshots and videos that are kept never show it.
		trace: 'off',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /global\.setup\.ts/,
		},
		{
			name: 'single-site',
			testMatch: /single-site\.spec\.ts/,
			dependencies: [ 'setup' ],
			use: {
				...devices[ 'Desktop Chrome' ],
				baseURL: process.env.REAL_SINGLE_URL ?? 'http://localhost:8888',
				storageState: 'build/real-auth-single.json',
			},
		},
		{
			name: 'multisite',
			testMatch: /multisite\.spec\.ts/,
			dependencies: [ 'setup' ],
			use: {
				...devices[ 'Desktop Chrome' ],
				baseURL: process.env.REAL_NETWORK_URL ?? 'http://localhost:8889',
				storageState: 'build/real-auth-network.json',
			},
		},
	],
} );
