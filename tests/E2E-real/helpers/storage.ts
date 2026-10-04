import fs from 'node:fs';
import path from 'node:path';
import * as azure from './azure';
import * as s3 from './s3';

/**
 * The storage a real run drives, whichever provider it is: REAL_PROVIDER
 * picks `azure` (the default) or `s3`. The specs are the same journeys for
 * both; everything that differs between them (the form, the names the
 * screens show, the refusal of a private container or bucket, the public
 * URL) is answered here, and the byte-for-byte checks go to the provider's
 * own SDK.
 */
export type Journey = 'single' | 'network';
export type Provider = 'azure' | 's3';

export interface RealRun {
	provider: Provider;
	/** Azure only: the storage account and its key ('' for S3). */
	account: string;
	key: string;
	/** S3 only: the service, its endpoint and keys. */
	s3?: s3.S3Target;
	/** The container (Azure) or bucket (S3) of the journey that read this run. */
	container: string;
	containers: Record< Journey, string >;
	runId: string;
}

export const PROVIDER: Provider = process.env.REAL_PROVIDER === 's3' ? 's3' : 'azure';

/** Written by the global setup; read by every spec. */
export const RUN_FILE = path.resolve( __dirname, '../../../build/real-run.json' );

export function newRun( runId: string ): RealRun {
	const containers = { single: `e2e-${ runId }-single`, network: `e2e-${ runId }-network` };
	if ( PROVIDER === 's3' ) {
		const target = s3.s3FromEnv();
		const buckets = target.fixedBucket ? { single: target.fixedBucket, network: target.fixedBucket } : containers;
		return { provider: 's3', account: '', key: '', s3: target, container: buckets.single, containers: buckets, runId };
	}
	return { provider: 'azure', ...azure.credentialsFromEnv(), container: containers.single, containers, runId };
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

// ── The containers or buckets of a run ──────────────────

/** One per journey, readable by anyone: the way a deployment must be configured. */
export async function createContainers( run: RealRun ): Promise< void > {
	if ( run.s3?.fixedBucket ) {
		await s3.emptyPrefix( run.s3, run.s3.fixedBucket, 'uploads/' );
		return;
	}
	if ( run.s3 ) {
		for ( const bucket of Object.values( run.containers ) ) await s3.createPublicBucket( run.s3, bucket );
		return;
	}
	await azure.createContainers( run );
}

export async function deleteContainers( run: RealRun ): Promise< void > {
	if ( run.s3?.fixedBucket ) {
		await s3.emptyPrefix( run.s3, run.s3.fixedBucket, 'uploads/' );
		return;
	}
	if ( run.s3 ) {
		const target = run.s3;
		const results = await Promise.allSettled( Object.values( run.containers ).map( ( bucket ) => s3.deleteBucket( target, bucket ) ) );
		const failed = results.filter( ( r ): r is PromiseRejectedResult => r.status === 'rejected' );
		if ( failed.length ) throw new AggregateError( failed.map( ( r ) => r.reason ), 'Some buckets were not deleted' );
		return;
	}
	await azure.deleteContainers( run );
}

/**
 * Before a journey: a fixed bucket, shared by both journeys, starts without
 * the previous journey's objects (the plugin never deletes them on
 * uninstall). A container or bucket of the journey's own is already empty.
 */
export async function startJourney( run: RealRun ): Promise< void > {
	if ( run.s3?.fixedBucket ) await s3.emptyPrefix( run.s3, run.s3.fixedBucket, 'uploads/' );
}

/** Whether the suite can make a private container or bucket: not with keys limited to one fixed bucket. */
export function canMakePrivateContainer( run: RealRun ): boolean {
	return ! run.s3?.fixedBucket;
}

/** A private container or bucket next to the run's, to prove the plugin refuses it. */
export async function createPrivateContainer( run: RealRun, name: string ): Promise< void > {
	return run.s3 ? s3.createPrivateBucket( run.s3, name ) : azure.createPrivateContainer( run, name );
}

export async function deleteNamedContainer( run: RealRun, name: string ): Promise< void > {
	return run.s3 ? s3.deleteBucket( run.s3, name ) : azure.deleteNamedContainer( run, name );
}

// ── What is in it ───────────────────────────────────────

export async function listKeys( run: RealRun, prefix = '' ): Promise< string[] > {
	return run.s3 ? s3.listKeys( run.s3, run.container, prefix ) : azure.listKeys( run, prefix );
}

export async function blobExists( run: RealRun, key: string ): Promise< boolean > {
	return run.s3 ? s3.objectExists( run.s3, run.container, key ) : azure.blobExists( run, key );
}

/**
 * Whether an object the plugin deleted is gone. The listing is the
 * authority: Google may answer a HEAD for a public object it served before
 * from a cache after the object was deleted. When the HEAD and the listing
 * disagree, the run's log says so, and the listing wins.
 */
export async function blobGone( run: RealRun, key: string ): Promise< boolean > {
	if ( ! ( await blobExists( run, key ) ) ) {
		return true;
	}
	const listed = ( await listKeys( run, key ) ).includes( key );
	if ( ! listed ) {
		// eslint-disable-next-line no-console
		console.log( `[blobGone] ${ key }: HEAD still answers, the listing no longer has it (a cached answer)` );
	}
	return ! listed;
}

export async function blobSize( run: RealRun, key: string ): Promise< number > {
	return run.s3 ? s3.objectSize( run.s3, run.container, key ) : azure.blobSize( run, key );
}

export async function blobMd5( run: RealRun, key: string ): Promise< string > {
	return run.s3 ? s3.objectMd5( run.s3, run.container, key ) : azure.blobMd5( run, key );
}

/**
 * '' when the object holds exactly the bytes whose MD5 is `expected`;
 * otherwise what was read, read again two seconds later, and what the
 * service says about the object. A mismatch has come and gone on Google
 * Cloud Storage; the second read says whether the object or the read was
 * wrong. It never turns a mismatch into a pass.
 */
export async function bytesDiffer( run: RealRun, key: string, expected: string ): Promise< string > {
	const first = await blobMd5( run, key );
	if ( first === expected ) return '';
	await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );
	const second = await blobMd5( run, key );
	const facts = run.s3 ? await s3.objectFacts( run.s3, run.container, key ) : `size ${ await blobSize( run, key ) }`;
	return `expected md5 ${ expected }, read ${ first }, read again ${ second } (${ second === expected ? 'the object is right: the first read was stale' : 'the object itself differs' }); ${ facts }`;
}

/** Put an object there as someone else would have: a bucket reused from another install. */
export async function putObject( run: RealRun, key: string, body: string ): Promise< void > {
	return run.s3 ? s3.putObject( run.s3, run.container, key, body ) : azure.putBlob( run, key, body );
}

export async function deleteObject( run: RealRun, key: string ): Promise< void > {
	return run.s3 ? s3.deleteObject( run.s3, run.container, key ) : azure.deleteBlob( run, key );
}

export const fileMd5 = azure.fileMd5;

/** What an object was stored with: Cache-Control ('' when none), storage class or tier, content type. */
export async function objectProps( run: RealRun, key: string ): Promise< { cacheControl: string; storageClass: string; contentType: string } > {
	return run.s3 ? s3.objectProps( run.s3, run.container, key ) : azure.blobProps( run, key );
}

/**
 * The class Settings › Serving's "infrequent" stores new objects in on this
 * service, or '' when the service offers none (the setting is not shown and
 * new objects go in the service's default class): Azure's Cool tier, and
 * STANDARD_IA on the S3 services whose preset says so (Amazon S3, R2).
 */
export function infrequentClass( run: RealRun ): string {
	if ( ! run.s3 ) return 'Cool';
	return [ 'aws', 'r2' ].includes( run.s3.preset ) ? 'STANDARD_IA' : '';
}

/**
 * What the storage still holds of an unfinished upload of `key`: on S3 the
 * parts of the multipart upload `uploadName` (-1 once the service knows no
 * such upload: completed or aborted); on Azure the blob's uncommitted
 * blocks (0 once a commit took them in or there are none).
 */
export async function unfinishedParts( run: RealRun, key: string, uploadName: string ): Promise< number > {
	return run.s3 ? s3.uploadParts( run.s3, run.container, key, uploadName ) : azure.uncommittedBlocks( run, key );
}

/** unfinishedParts()' answer once nothing of the upload is left unfinished. */
export function noUnfinishedParts( run: RealRun ): number {
	return run.s3 ? -1 : 0;
}

// ── What the screens ask and show ───────────────────────

/** The Connection form, filled with the run's credentials unless another secret is given. */
export type ConnectionForm =
	| { provider: 'azure'; account: string; key: string; container: string }
	| { provider: 's3'; preset: string; region: string; endpoint: string; bucket: string; keyId: string; secret: string; publicUrl: string };

export function form( run: RealRun, container: string = run.container, secret?: string ): ConnectionForm {
	if ( run.s3 ) {
		return {
			provider: 's3',
			preset: run.s3.preset,
			region: run.s3.region,
			endpoint: run.s3.endpoint,
			bucket: container,
			keyId: run.s3.accessKeyId,
			secret: secret ?? run.s3.secretAccessKey,
			publicUrl: s3.publicBase( run.s3, container ),
		};
	}
	return { provider: 'azure', account: run.account, key: secret ?? run.key, container };
}

/** A secret of the right shape that opens nothing. */
export function wrongSecret( run: RealRun ): string {
	return run.s3
		? 'not-the-secret-but-the-right-shape-for-one0'
		: Buffer.from( 'not the key, but the right shape for one....................' ).toString( 'base64' );
}

export function secret( run: RealRun ): string {
	return run.s3 ? run.s3.secretAccessKey : run.key;
}

/** The provider_config field that holds the secret. */
export function secretField( run: RealRun ): string {
	return run.s3 ? 'secret_access_key' : 'access_key';
}

/** What the Connection info and Status › System show to name where the media lives. */
export function identity( run: RealRun ): string[] {
	return run.s3 ? [ run.container ] : [ run.account, run.container ];
}

/** The host the Offloading screen says media is served from. */
export function servedFromHost( run: RealRun ): string {
	return run.s3 ? new URL( s3.publicBase( run.s3, run.container ) ).hostname : `${ run.account }.blob.core.windows.net`;
}

/** Where every media URL of the journey's container starts. */
export function publicUrlPrefix( run: RealRun ): string {
	return run.s3 ? s3.publicBase( run.s3, run.container ) + '/' : `https://${ run.account }.blob.core.windows.net/${ run.container }/`;
}

/** What Test Connection says of a container or bucket browsers cannot read. */
export function privateRefusal( run: RealRun ): RegExp {
	return run.s3 ? /not readable at the Public URL/i : /private[\s\S]*public access level/i;
}
