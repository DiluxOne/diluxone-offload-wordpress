/**
 * The end-to-end suite's JavaScript coverage (`make coverage-e2e`): Chromium's
 * V8 coverage of the plugin's admin scripts, assets/js/*.js, reported per file
 * in lines and branches. The fixture in helpers/test.ts adds each test's
 * entries to the cache in build/e2e-coverage/; report.mjs builds the report.
 */
export const coverageOptions = {
	name: 'DiluxOne Offload: end-to-end JavaScript coverage',
	outputDir: 'build/e2e-coverage',
	entryFilter: ( entry ) => /\/assets\/js\/[^/?]+\.js/.test( entry.url ),
	sourcePath: ( filePath ) => filePath.replace( /^.*?\/(assets\/js\/[^/?]+\.js).*$/, '$1' ),
	all: { dir: [ 'assets/js' ], filter: { '**/*.js': 'js' } },
	reports: [ 'v8', 'console-details', 'json-summary', 'lcovonly' ],
	clean: true,
};
