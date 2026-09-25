/**
 * Delete the real suite's leftover containers.
 *
 * Every run deletes its own containers in the Playwright teardown and in the
 * PHPUnit class teardown. A run that is cancelled or whose runner dies never
 * gets there, so this runs last in the workflow, whatever happened: it
 * removes this run's containers by name and any `e2e-*` container older
 * than MAX_AGE_MINUTES (default 60), which no live run can still own.
 *
 * Plain Node on purpose: it must work when nothing else in the job did.
 */
const { BlobServiceClient, StorageSharedKeyCredential } = require( '@azure/storage-blob' );

const account = process.env.AZURE_E2E_ACCOUNT;
const key = process.env.AZURE_E2E_KEY;
if ( ! account || ! key ) {
	console.error( 'AZURE_E2E_ACCOUNT and AZURE_E2E_KEY are required.' );
	process.exit( 1 );
}

const runId = ( process.env.GITHUB_RUN_ID ?? '' ).toLowerCase();
const maxAgeMinutes = Number( process.env.MAX_AGE_MINUTES ?? 60 );
if ( ! Number.isFinite( maxAgeMinutes ) || maxAgeMinutes < 0 ) {
	// A typo here must not read as "everything is old enough".
	console.error( `MAX_AGE_MINUTES must be a number of minutes, got "${ process.env.MAX_AGE_MINUTES }".` );
	process.exit( 1 );
}
const maxAgeMs = maxAgeMinutes * 60 * 1000;

( async () => {
	const service = new BlobServiceClient( `https://${ account }.blob.core.windows.net`, new StorageSharedKeyCredential( account, key ) );
	let kept = 0;
	const failed = [];
	for await ( const c of service.listContainers( { prefix: 'e2e-' } ) ) {
		const age = Date.now() - new Date( c.properties.lastModified ).getTime();
		const own = runId !== '' && c.name.startsWith( `e2e-${ runId }-` );
		if ( ! own && age < maxAgeMs ) {
			kept++;
			continue;
		}
		try {
			// deleteIfExists: another sweep may have got there first.
			await service.getContainerClient( c.name ).deleteIfExists();
			console.log( `deleted ${ c.name } (${ own ? 'this run' : Math.round( age / 60000 ) + ' min old' })` );
		} catch ( e ) {
			failed.push( c.name );
			console.error( `could not delete ${ c.name }: ${ e.message }` );
		}
	}
	console.log( `sweep done: ${ kept } container(s) kept as possibly live` );
	process.exit( failed.length ? 1 : 0 );
} )();
