import { BlobServiceClient, ContainerClient, StorageSharedKeyCredential } from '@azure/storage-blob';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

/**
 * The storage account the real suite runs against, and the container each
 * journey owns. Every run creates one container per journey and deletes
 * them at the end, so neither runs nor journeys see each other's objects
 * (the plugin never deletes blobs on uninstall, so one journey's leftovers
 * would be another's "unexpected keys") and the account stays empty.
 */
export type Journey = 'single' | 'network';

export interface RealRun {
	account: string;
	key: string;
	/** The container of the journey that read this run. */
	container: string;
	containers: Record< Journey, string >;
	runId: string;
}

/** Written by the global setup; read by every spec and by the PHPUnit real-provider tests. */
export const RUN_FILE = path.resolve( __dirname, '../../../build/real-azure.json' );

export function credentialsFromEnv(): { account: string; key: string } {
	let account = process.env.AZURE_E2E_ACCOUNT ?? '';
	let key = process.env.AZURE_E2E_KEY ?? '';

	// Local runs keep them in a git-ignored .env.e2e at the repo root.
	if ( ! account || ! key ) {
		const envFile = path.resolve( __dirname, '../../../.env.e2e' );
		if ( fs.existsSync( envFile ) ) {
			for ( const line of fs.readFileSync( envFile, 'utf8' ).split( '\n' ) ) {
				const m = line.match( /^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/ );
				if ( ! m ) continue;
				if ( m[ 1 ] === 'AZURE_E2E_ACCOUNT' && ! account ) account = m[ 2 ];
				if ( m[ 1 ] === 'AZURE_E2E_KEY' && ! key ) key = m[ 2 ];
			}
		}
	}

	if ( ! account || ! key ) {
		throw new Error( 'AZURE_E2E_ACCOUNT and AZURE_E2E_KEY are required (env or .env.e2e).' );
	}
	return { account, key };
}

/** The run as the given journey sees it: `container` is that journey's own. */
export function readRun( journey: Journey ): RealRun {
	const run = JSON.parse( fs.readFileSync( RUN_FILE, 'utf8' ) ) as RealRun;
	return { ...run, container: run.containers[ journey ] };
}

export function writeRun( run: RealRun ): void {
	fs.mkdirSync( path.dirname( RUN_FILE ), { recursive: true } );
	fs.writeFileSync( RUN_FILE, JSON.stringify( run, null, 2 ) + '\n', { mode: 0o600 } );
}

export function containerClient( run: RealRun ): ContainerClient {
	const credential = new StorageSharedKeyCredential( run.account, run.key );
	const service = new BlobServiceClient( `https://${ run.account }.blob.core.windows.net`, credential );
	return service.getContainerClient( run.container );
}

/** The run's containers, public at blob level: the way a deployment must be configured for browsers to load media. */
export async function createContainers( run: RealRun ): Promise< void > {
	for ( const container of Object.values( run.containers ) ) {
		await containerClient( { ...run, container } ).createIfNotExists( { access: 'blob' } );
	}
}

/** Every container gets its delete attempted; a failure is reported after the rest were tried. */
export async function deleteContainers( run: RealRun ): Promise< void > {
	const results = await Promise.allSettled(
		Object.values( run.containers ).map( ( container ) => containerClient( { ...run, container } ).deleteIfExists() )
	);
	const failed = results.filter( ( r ): r is PromiseRejectedResult => r.status === 'rejected' );
	if ( failed.length ) throw new AggregateError( failed.map( ( r ) => r.reason ), 'Some containers were not deleted' );
}

/** A private container next to the run's, to prove the plugin refuses it. */
export async function createPrivateContainer( run: RealRun, name: string ): Promise< void > {
	await containerClient( { ...run, container: name } ).createIfNotExists();
}

export async function deleteNamedContainer( run: RealRun, name: string ): Promise< void > {
	await containerClient( { ...run, container: name } ).deleteIfExists();
}

/** Every object key in the container, optionally under a prefix. */
export async function listKeys( run: RealRun, prefix = '' ): Promise< string[] > {
	const keys: string[] = [];
	for await ( const blob of containerClient( run ).listBlobsFlat( { prefix } ) ) {
		keys.push( blob.name );
	}
	return keys.sort();
}

export async function blobExists( run: RealRun, key: string ): Promise< boolean > {
	return containerClient( run ).getBlobClient( key ).exists();
}

export async function blobSize( run: RealRun, key: string ): Promise< number > {
	const props = await containerClient( run ).getBlobClient( key ).getProperties();
	return props.contentLength ?? -1;
}

/** MD5 of the object's bytes, computed here from a fresh download — not trusted from metadata. */
export async function blobMd5( run: RealRun, key: string ): Promise< string > {
	const buffer = await containerClient( run ).getBlobClient( key ).downloadToBuffer();
	return createHash( 'md5' ).update( buffer ).digest( 'hex' );
}

export function fileMd5( file: string ): string {
	return createHash( 'md5' ).update( fs.readFileSync( file ) ).digest( 'hex' );
}
