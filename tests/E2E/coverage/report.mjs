import fs from 'node:fs';
import { CoverageReport } from 'monocart-coverage-reports';
import { coverageOptions } from './config.mjs';

/**
 * Turns the entries the end-to-end suite cached in build/e2e-coverage/ into
 * the report: the per-file table (executable lines, branches, functions, as
 * Istanbul counts them) on the console and in summary.md, the HTML report
 * (index.html), coverage-summary.json and lcov.info.
 */
const report = new CoverageReport( coverageOptions );
if ( ! report.hasCache() ) {
	console.error( 'No coverage entries in build/e2e-coverage/: run the suite with E2E_COVERAGE=1 first.' );
	process.exit( 1 );
}
const results = await report.generate();

const summary = JSON.parse( fs.readFileSync( `${ coverageOptions.outputDir }/coverage-summary.json`, 'utf8' ) );
const pct = ( m ) => ( m.total ? `${ ( ( m.covered / m.total ) * 100 ).toFixed( 2 ) } %` : 'n/a' );
const row = ( name, s ) => `| ${ name } | ${ s.lines.covered }/${ s.lines.total } | ${ pct( s.lines ) } | ${ s.branches.covered }/${ s.branches.total } | ${ pct( s.branches ) } | ${ s.functions.covered }/${ s.functions.total } | ${ pct( s.functions ) } |`;
const table = [
	'| File | Lines | Lines % | Branches | Branches % | Functions | Functions % |',
	'| --- | ---: | ---: | ---: | ---: | ---: | ---: |',
	...Object.keys( summary ).filter( ( k ) => k !== 'total' ).sort().map( ( k ) => row( k, summary[ k ] ) ),
	row( '**Total**', summary.total ),
];
fs.writeFileSync( `${ coverageOptions.outputDir }/summary.md`, table.join( '\n' ) + '\n' );
console.log( '\n' + table.join( '\n' ) );
console.log( `\nReport: ${ results.reportPath }` );
