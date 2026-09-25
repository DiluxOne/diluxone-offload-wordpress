import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { readRun, RealRun, listKeys, blobExists, blobMd5, fileMd5 } from './helpers/azure';
import { BASE_URL, wp, pluginState, nativeUploadsDir, filesUnder, md5Inside, attachedFile, attachmentUrl } from './helpers/wp';
import { FIXTURE_DIR, generateFixtures, seedMediaLibrary } from './helpers/fixtures';
import * as ui from './helpers/plugin';

/**
 * Two sites of one network, one container. The main site and a site created
 * for this run store the same file names; they must never share a key, each
 * must see only its own objects, and disconnecting one must leave the other
 * exactly as it was.
 */
const site = 'network';
const base = BASE_URL.network;
const SHARED = [ 'tiny-10k.png', 'small-300k.png', 'photo-1m.png' ];

test.describe.serial( 'multisite journey', () => {
	let run: RealRun;
	let blogId = 0;
	let other = '';
	let mainUploads = '';
	let otherUploads = '';
	let mainSeeded: number[] = [];
	let otherSeeded: number[] = [];
	let otherUploadId = 0;

	test.beforeAll( () => {
		run = readRun( 'network' );
		generateFixtures();
		blogId = Number( wp( site, [ 'site', 'create', `--slug=real-${ run.runId }`, '--title=Real suite', '--porcelain' ] ) );
		expect( blogId ).toBeGreaterThan( 1 );
		other = `${ base }/real-${ run.runId }`;
		mainUploads = nativeUploadsDir( site );
		otherUploads = nativeUploadsDir( site, other );
		expect( otherUploads ).toContain( `/sites/${ blogId }` );
	} );

	test( 'both sites are configured against the same container', async ( { page } ) => {
		for ( const home of [ base, other ] ) {
			await ui.goTab( page, home, 'cloud-provider' );
			expect( await ui.testConnection( page, { account: run.account, key: run.key, container: run.container } ) ).toMatch( /success/i );
			await ui.saveProvider( page );
		}
		expect( pluginState( site ) ).toBe( 'configured' );
		expect( pluginState( site, other ) ).toBe( 'configured' );
	} );

	test( 'the same file names on both sites become different keys', async ( { page } ) => {
		mainSeeded = seedMediaLibrary( site, SHARED );
		otherSeeded = seedMediaLibrary( site, SHARED, other );
		for ( const home of [ base, other ] ) {
			await ui.goTab( page, home, 'sync-offloading' );
			expect( await ui.runSyncToCompletion( page, 'scratch' ) ).toBe( 'success' );
			await ui.enableOffloadingFromModal( page );
		}
		expect( pluginState( site ) ).toBe( 'offloading_active' );
		expect( pluginState( site, other ) ).toBe( 'offloading_active' );

		const keys = await listKeys( run, 'uploads/' );
		const mainKeys = keys.filter( ( k ) => ! k.startsWith( 'uploads/sites/' ) );
		const otherKeys = keys.filter( ( k ) => k.startsWith( `uploads/sites/${ blogId }/` ) );
		// The other site's directory lives inside the main site's; it is not the main site's to sync.
		const mainOwn = filesUnder( site, mainUploads ).filter( ( f ) => ! f.startsWith( 'sites/' ) );
		expect( mainKeys.length ).toBe( mainOwn.length );
		expect( keys.filter( ( k ) => k.startsWith( 'uploads/sites/' ) && ! k.startsWith( `uploads/sites/${ blogId }/` ) ), 'nothing under another site\'s prefix came from the main site' ).toEqual( [] );
		expect( otherKeys.length ).toBe( filesUnder( site, otherUploads ).length );
		for ( const name of SHARED ) {
			expect( mainKeys.some( ( k ) => k.endsWith( '/' + name ) ), `${ name } under the main site` ).toBe( true );
			expect( otherKeys.some( ( k ) => k.endsWith( '/' + name ) ), `${ name } under sites/${ blogId }` ).toBe( true );
		}
		for ( const rel of filesUnder( site, otherUploads ) ) {
			expect( await blobMd5( run, `uploads/sites/${ blogId }/${ rel }` ) ).toBe( md5Inside( site, `${ otherUploads }/${ rel }` ) );
		}
	} );

	test( 'each site counts only its own objects', async ( { page } ) => {
		const keys = await listKeys( run, 'uploads/' );
		await ui.goTab( page, base, 'overview' );
		expect( await ui.refreshStats( page ) ).toBe( keys.filter( ( k ) => ! k.startsWith( 'uploads/sites/' ) ).length );
		await ui.goTab( page, other, 'overview' );
		expect( await ui.refreshStats( page ) ).toBe( keys.filter( ( k ) => k.startsWith( `uploads/sites/${ blogId }/` ) ).length );
	} );

	test( 'a Media Library upload on the other site lands under its prefix', async ( { page } ) => {
		// A name no other site has, so "not under the main site" means something.
		const upload = path.join( FIXTURE_DIR, `other-site-${ run.runId }.png` );
		fs.copyFileSync( path.join( FIXTURE_DIR, 'small-300k.png' ), upload );
		otherUploadId = await ui.uploadThroughMediaLibrary( page, other, upload );
		const file = attachedFile( site, otherUploadId, other );
		expect( await blobExists( run, `uploads/sites/${ blogId }/${ file }` ) ).toBe( true );
		expect( await blobExists( run, `uploads/${ file }` ), 'never under the main site' ).toBe( false );
		expect( attachmentUrl( site, otherUploadId, other ) ).toContain( `/${ run.container }/uploads/sites/${ blogId }/` );
	} );

	test( 'deleting local copies on one site does not touch the other', async ( { page } ) => {
		const mainOwnBefore = filesUnder( site, mainUploads ).filter( ( f ) => ! f.startsWith( 'sites/' ) );
		await ui.goTab( page, other, 'sync-offloading' );
		await ui.deleteLocalFiles( page );
		expect( filesUnder( site, otherUploads ).length ).toBe( 0 );
		expect( filesUnder( site, mainUploads ).filter( ( f ) => ! f.startsWith( 'sites/' ) ) ).toEqual( mainOwnBefore );

		// And the other way round: Delete Local Files on the main site leaves the other site's directory alone.
		await ui.goTab( page, other, 'sync-offloading' );
		await ui.disconnectToCompletion( page );
		const otherBefore = filesUnder( site, otherUploads );
		expect( otherBefore.length ).toBeGreaterThan( 0 );
		await ui.goTab( page, base, 'sync-offloading' );
		await ui.deleteLocalFiles( page );
		expect( filesUnder( site, mainUploads ).filter( ( f ) => ! f.startsWith( 'sites/' ) ).length ).toBe( 0 );
		expect( filesUnder( site, otherUploads ) ).toEqual( otherBefore );
		// Back to where the next step expects the other site: its files are all
		// in the cloud already (SYNCED), so offloading is one click away.
		await ui.goTab( page, other, 'sync-offloading' );
		await ui.completeSyncAndEnable( page );
		expect( pluginState( site, other ) ).toBe( 'offloading_active' );
	} );

	test( 'disconnecting one site restores only its files and leaves the other offloading', async ( { page } ) => {
		const mainKeysBefore = ( await listKeys( run, 'uploads/' ) ).filter( ( k ) => ! k.startsWith( 'uploads/sites/' ) );
		await ui.goTab( page, other, 'sync-offloading' );
		await ui.disconnectToCompletion( page );
		expect( pluginState( site, other ) ).toBe( 'configured' );
		expect( pluginState( site ) ).toBe( 'offloading_active' );
		for ( const key of await listKeys( run, `uploads/sites/${ blogId }/` ) ) {
			const rel = key.replace( `uploads/sites/${ blogId }/`, '' );
			expect( md5Inside( site, `${ otherUploads }/${ rel }` ), rel ).toBe( await blobMd5( run, key ) );
		}
		expect( ( await listKeys( run, 'uploads/' ) ).filter( ( k ) => ! k.startsWith( 'uploads/sites/' ) ) ).toEqual( mainKeysBefore );
	} );

	test( 'disconnecting the main site ignores the other site\'s objects', async ( { page } ) => {
		const otherKeysBefore = await listKeys( run, `uploads/sites/${ blogId }/` );
		const otherFilesBefore = filesUnder( site, otherUploads );
		await ui.goTab( page, base, 'sync-offloading' );
		await ui.disconnectToCompletion( page );
		expect( pluginState( site ) ).toBe( 'configured' );
		expect( filesUnder( site, otherUploads ), 'the other site\'s directory is untouched by the main site\'s Disconnect' ).toEqual( otherFilesBefore );
		expect( await listKeys( run, `uploads/sites/${ blogId }/` ) ).toEqual( otherKeysBefore );
	} );

	test.afterAll( () => {
		// The setup of the next run wipes provider config and rows; here only
		// what would otherwise accumulate: the seeded media and the run site.
		for ( const id of mainSeeded ) {
			try { wp( site, [ 'post', 'delete', String( id ), '--force' ] ); } catch { /* gone */ }
		}
		if ( blogId ) {
			try { wp( site, [ 'site', 'delete', String( blogId ), '--yes' ] ); } catch { /* gone */ }
		}
	} );
} );
