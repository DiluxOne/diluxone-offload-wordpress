import fs from 'node:fs';
import path from 'node:path';
import { test as base, CDPSession, Page } from '@playwright/test';
import { CoverageReport } from 'monocart-coverage-reports';
import { coverageOptions } from '../coverage/config.mjs';

export { expect } from '@playwright/test';
export type { Page, Locator, BrowserContext } from '@playwright/test';

/**
 * The suite's `test`. With E2E_COVERAGE=1 (`make coverage-e2e`) every page a
 * test opens, the `page` fixture's and any `context.newPage()`, records
 * Chromium's V8 coverage of the plugin's admin scripts (assets/js/) from
 * before its first navigation; the entries go to the report's cache in
 * build/e2e-coverage/ and the Make target turns them into the report.
 * Without the variable this is Playwright's own `test`, untouched.
 *
 * The counts are taken over CDP rather than with page.coverage: a document's
 * counts are lost once the page has navigated away from it (a reload after
 * an action, the next goto), so they are taken, and the counters reset, as
 * each document unloads, and once more at the end.
 */
const COVERAGE = process.env.E2E_COVERAGE === '1';

const PLUGIN_JS = /\/wp-content\/plugins\/[^/]+\/(assets\/js\/[^/?]+\.js)/;
const ROOT = path.resolve( __dirname, '../../..' );

type Entry = { url: string; scriptId: string; source: string; functions: unknown[] };

/** Start the page's coverage; returns what takes its counts (one at a time) into `into`. */
async function recorder( page: Page, cdp: CDPSession, into: Entry[] ): Promise< () => Promise< void > > {
	// The coverage result names a script by id only once the debugger has
	// reported it, so the URLs come from the debugger's own events.
	const urls = new Map< string, string >();
	cdp.on( 'Debugger.scriptParsed', ( event ) => {
		if ( PLUGIN_JS.test( event.url ) ) {
			urls.set( event.scriptId, event.url );
		}
	} );
	await cdp.send( 'Debugger.enable' );
	await cdp.send( 'Profiler.enable' );
	await cdp.send( 'Profiler.startPreciseCoverage', { callCount: true, detailed: true } );
	let queue = Promise.resolve();
	const take = () => {
		queue = queue.then( async () => {
			const { result } = await cdp.send( 'Profiler.takePreciseCoverage' );
			for ( const script of result ) {
				const url = urls.get( script.scriptId ) ?? script.url;
				const match = PLUGIN_JS.exec( url );
				if ( match ) {
					into.push( { url, scriptId: script.scriptId, source: fs.readFileSync( path.join( ROOT, match[ 1 ] ), 'utf8' ), functions: script.functions } );
				}
			}
		} ).catch( () => undefined );
		return queue;
	};
	// The document's last moment: once the navigation has started, the
	// browser holds every DevTools command back until the next document has
	// replaced this one, counts and all. So the page stops in the debugger on
	// beforeunload, the counts are taken while it waits, and it goes on.
	cdp.on( 'Debugger.paused', () => {
		void take().then( () => cdp.send( 'Debugger.resume' ) ).catch( () => undefined );
	} );
	await page.addInitScript( () => {
		window.addEventListener( 'beforeunload', () => {
			// eslint-disable-next-line no-debugger
			debugger;
		}, true );
	} );
	return take;
}

export const test = base.extend( {
	context: async ( { context }, use ) => {
		if ( ! COVERAGE ) {
			await use( context );
			return;
		}
		const takers = new Map< Page, () => Promise< void > >();
		const entries: Entry[] = [];
		const newPage = context.newPage.bind( context );
		context.newPage = async () => {
			const page: Page = await newPage();
			const take = await recorder( page, await context.newCDPSession( page ), entries );
			takers.set( page, take );
			const close = page.close.bind( page );
			page.close = async ( options ) => {
				await take();
				return close( options );
			};
			return page;
		};
		await use( context );
		await Promise.all( [ ...takers.values() ].map( ( take ) => take() ) );
		if ( entries.length ) {
			await new CoverageReport( coverageOptions ).add( entries );
		}
	},
} );
