import {
	S3Client,
	CreateBucketCommand,
	DeleteBucketCommand,
	PutBucketPolicyCommand,
	ListObjectsV2Command,
	DeleteObjectsCommand,
	HeadObjectCommand,
	GetObjectCommand,
	PutObjectCommand,
	DeleteObjectCommand,
	ListPartsCommand,
} from '@aws-sdk/client-s3';
import { createHash } from 'node:crypto';

/**
 * The S3-compatible side of the real suite. Locally and on every pull
 * request it runs against a throwaway S3 server on wp-env's network
 * (`make s3-up`: RustFS, since MinIO no longer publishes images), with a
 * bucket per journey created and deleted by the run, readable by anyone
 * through a bucket policy the way a deployment must be. The S3_E2E_*
 * variables point it at another service; with S3_E2E_BUCKET the run uses
 * that bucket, already public, for both journeys, and empties its uploads/
 * before each one and at the end.
 */
export interface S3Target {
	preset: string;
	endpoint: string;
	region: string;
	accessKeyId: string;
	secretAccessKey: string;
	/** Public URL pattern with {bucket}. */
	publicUrl: string;
	pathStyle: boolean;
	/**
	 * A bucket that already exists and is public (a real service, where the
	 * suite's keys cannot create one or make it public), or '' to create one
	 * per journey (the local server).
	 */
	fixedBucket: string;
}

export function s3FromEnv(): S3Target {
	const env = ( name: string, fallback: string ) => process.env[ name ] || fallback;
	return {
		preset: env( 'S3_E2E_PRESET', 'custom' ),
		endpoint: env( 'S3_E2E_ENDPOINT', 'http://s3local:9000' ),
		region: env( 'S3_E2E_REGION', 'us-east-1' ),
		accessKeyId: env( 'S3_E2E_ACCESS_KEY_ID', 'e2eadmin' ),
		secretAccessKey: env( 'S3_E2E_SECRET_ACCESS_KEY', 'e2eadmin-secret' ),
		publicUrl: env( 'S3_E2E_PUBLIC_URL', 'http://s3local:9000/{bucket}' ),
		pathStyle: env( 'S3_E2E_PRESET', 'custom' ) !== 'aws',
		fixedBucket: env( 'S3_E2E_BUCKET', '' ),
	};
}

export function publicBase( target: S3Target, bucket: string ): string {
	return target.publicUrl.replace( '{bucket}', bucket ).replace( /\/$/, '' );
}

function client( target: S3Target ): S3Client {
	return new S3Client( {
		endpoint: target.endpoint,
		region: target.region,
		forcePathStyle: target.pathStyle,
		credentials: { accessKeyId: target.accessKeyId, secretAccessKey: target.secretAccessKey },
	} );
}

/** A bucket anyone can read objects from: what the plugin needs to serve media. */
export async function createPublicBucket( target: S3Target, bucket: string ): Promise< void > {
	await createPrivateBucket( target, bucket );
	await client( target ).send(
		new PutBucketPolicyCommand( {
			Bucket: bucket,
			Policy: JSON.stringify( {
				Version: '2012-10-17',
				Statement: [ { Effect: 'Allow', Principal: { AWS: [ '*' ] }, Action: [ 's3:GetObject' ], Resource: [ `arn:aws:s3:::${ bucket }/*` ] } ],
			} ),
		} )
	);
}

export async function createPrivateBucket( target: S3Target, bucket: string ): Promise< void > {
	try {
		await client( target ).send( new CreateBucketCommand( { Bucket: bucket } ) );
	} catch ( e ) {
		if ( ! /BucketAlready(OwnedByYou|Exists)/.test( ( e as Error ).name ) ) throw e;
	}
}

/** Every object goes, then the bucket. A bucket that is not there is fine. */
export async function deleteBucket( target: S3Target, bucket: string ): Promise< void > {
	const s3 = client( target );
	try {
		for ( ;; ) {
			const page = await s3.send( new ListObjectsV2Command( { Bucket: bucket } ) );
			const objects = ( page.Contents ?? [] ).map( ( o ) => ( { Key: o.Key as string } ) );
			if ( ! objects.length ) break;
			await s3.send( new DeleteObjectsCommand( { Bucket: bucket, Delete: { Objects: objects } } ) );
		}
		await s3.send( new DeleteBucketCommand( { Bucket: bucket } ) );
	} catch ( e ) {
		if ( ! /NoSuchBucket|NotFound/.test( ( e as Error ).name ) ) throw e;
	}
}

/** Every object under a prefix goes; the bucket stays. */
export async function emptyPrefix( target: S3Target, bucket: string, prefix: string ): Promise< void > {
	const s3 = client( target );
	for ( ;; ) {
		const page = await s3.send( new ListObjectsV2Command( { Bucket: bucket, Prefix: prefix } ) );
		const objects = ( page.Contents ?? [] ).map( ( o ) => ( { Key: o.Key as string } ) );
		if ( ! objects.length ) return;
		await s3.send( new DeleteObjectsCommand( { Bucket: bucket, Delete: { Objects: objects } } ) );
	}
}

export async function listKeys( target: S3Target, bucket: string, prefix = '' ): Promise< string[] > {
	const s3 = client( target );
	const keys: string[] = [];
	let token: string | undefined;
	do {
		const page = await s3.send( new ListObjectsV2Command( { Bucket: bucket, Prefix: prefix, ContinuationToken: token } ) );
		for ( const o of page.Contents ?? [] ) keys.push( o.Key as string );
		token = page.IsTruncated ? page.NextContinuationToken : undefined;
	} while ( token );
	return keys.sort();
}

export async function objectExists( target: S3Target, bucket: string, key: string ): Promise< boolean > {
	try {
		await client( target ).send( new HeadObjectCommand( { Bucket: bucket, Key: key } ) );
		return true;
	} catch ( e ) {
		if ( /NotFound|NoSuchKey/.test( ( e as Error ).name ) ) return false;
		throw e;
	}
}

export async function objectSize( target: S3Target, bucket: string, key: string ): Promise< number > {
	const head = await client( target ).send( new HeadObjectCommand( { Bucket: bucket, Key: key } ) );
	return head.ContentLength ?? -1;
}

/** What the service says about an object, for a failure message. */
export async function objectFacts( target: S3Target, bucket: string, key: string ): Promise< string > {
	const head = await client( target ).send( new HeadObjectCommand( { Bucket: bucket, Key: key } ) );
	return `size ${ head.ContentLength }, ETag ${ head.ETag }, last modified ${ head.LastModified?.toISOString() }`;
}

/**
 * The MD5 the service computed of what it stores, from the object's ETag,
 * or '' when the ETag is not one (a multipart or composite object). Read
 * from the object's metadata, never from a cached copy of its bytes.
 */
export async function storedMd5( target: S3Target, bucket: string, key: string ): Promise< string > {
	const head = await client( target ).send( new HeadObjectCommand( { Bucket: bucket, Key: key } ) );
	const etag = ( head.ETag ?? '' ).replace( /"/g, '' ).toLowerCase();
	return /^[0-9a-f]{32}$/.test( etag ) ? etag : '';
}

/** MD5 of the object's bytes, from a fresh download: a multipart ETag is not one. */
export async function objectMd5( target: S3Target, bucket: string, key: string ): Promise< string > {
	const object = await client( target ).send( new GetObjectCommand( { Bucket: bucket, Key: key } ) );
	const bytes = await ( object.Body as { transformToByteArray(): Promise< Uint8Array > } ).transformToByteArray();
	return createHash( 'md5' ).update( bytes ).digest( 'hex' );
}

/** An object put there by someone else: what a reused bucket already holds. */
export async function putObject( target: S3Target, bucket: string, key: string, body: string ): Promise< void > {
	await client( target ).send( new PutObjectCommand( { Bucket: bucket, Key: key, Body: body } ) );
}

/** Deletes one object; a key that is already gone is fine (Google answers NoSuchKey where S3 answers 204). */
export async function deleteObject( target: S3Target, bucket: string, key: string ): Promise< void > {
	try {
		await client( target ).send( new DeleteObjectCommand( { Bucket: bucket, Key: key } ) );
	} catch ( e ) {
		if ( ! /NoSuchKey|NotFound/.test( ( e as Error ).name ) ) throw e;
	}
}

/** What an object was stored with: its Cache-Control, its storage class (S3 leaves STANDARD unsaid) and its type. */
export async function objectProps( target: S3Target, bucket: string, key: string ): Promise< { cacheControl: string; storageClass: string; contentType: string } > {
	const head = await client( target ).send( new HeadObjectCommand( { Bucket: bucket, Key: key } ) );
	return { cacheControl: head.CacheControl ?? '', storageClass: head.StorageClass ?? 'STANDARD', contentType: head.ContentType ?? '' };
}

/** The parts an unfinished multipart upload holds, or -1 when the service knows no such upload (finished or aborted). */
export async function uploadParts( target: S3Target, bucket: string, key: string, uploadId: string ): Promise< number > {
	try {
		const page = await client( target ).send( new ListPartsCommand( { Bucket: bucket, Key: key, UploadId: uploadId } ) );
		return ( page.Parts ?? [] ).length;
	} catch ( e ) {
		if ( /NoSuchUpload|NotFound|NoSuchKey/.test( ( e as Error ).name ) ) return -1;
		throw e;
	}
}
