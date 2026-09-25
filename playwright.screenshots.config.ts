import { defineConfig, devices } from '@playwright/test';

/**
 * The wordpress.org listing screenshots (`.wordpress-org/screenshot-N.png`),
 * taken from the real plugin on the dev wp-env against a real Azure
 * account (the real suite's credentials). `make screenshots`.
 */
export default defineConfig( {
	testDir: './tests/screenshots',
	timeout: 20 * 60 * 1000,
	expect: { timeout: 30_000 },
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	outputDir: 'build/screenshots-results',
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL: process.env.REAL_SINGLE_URL ?? 'http://localhost:8888',
		// 160 px of admin menu + the 1600 px the listing shows; tall enough
		// for the toolbar and a core notice above the 900 px that are kept.
		viewport: { width: 1760, height: 1100 },
		deviceScaleFactor: 1,
		// The access key is typed into the provider form: no traces.
		trace: 'off',
		screenshot: 'off',
		video: 'off',
	},
} );
