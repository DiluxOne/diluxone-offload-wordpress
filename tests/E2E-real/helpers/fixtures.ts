import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { randomBytes } from 'node:crypto';
import { wp, shell, REPO_IN_CONTAINER, Site } from './wp';

/**
 * The media set every run seeds: real PNGs WordPress will make thumbnails
 * of, videos it will not touch, a document, a plain-text file and names
 * with spaces and accents. Sizes sit on both sides of the provider's
 * limits: the 4 MiB block (single PUT vs Put Block) and the 10 MB chunked
 * upload threshold of the parallel sync.
 */
export const FIXTURE_DIR = path.resolve( __dirname, '../../../build/real-fixtures' );

export interface Fixture {
	name: string;
	bytes: number;
}

export const FIXTURES: Fixture[] = [
	{ name: 'tiny-10k.png', bytes: 10 * 1024 },
	{ name: 'small-300k.png', bytes: 300 * 1024 },
	{ name: 'photo-1m.png', bytes: 1024 * 1024 },
	{ name: 'café photo 3900k.png', bytes: 3900 * 1024 },
	{ name: 'photo-4100k.png', bytes: 4100 * 1024 },
	{ name: 'clip-9m.mp4', bytes: 9 * 1024 * 1024 },
	{ name: 'clip-12m.mp4', bytes: 12 * 1024 * 1024 },
	{ name: 'video-60m.mp4', bytes: 60 * 1024 * 1024 },
	{ name: 'notes.txt', bytes: 2048 },
	{ name: 'brochure.pdf', bytes: 4096 },
	// Names WordPress keeps as they are: case, non-Latin script, an emoji, length.
	{ name: 'PHOTO-UPPER.PNG', bytes: 80 * 1024 },
	{ name: 'photo-upper.png', bytes: 70 * 1024 },
	{ name: '写真 📷 photo.png', bytes: 60 * 1024 },
	{ name: 'long-' + 'x'.repeat( 180 ) + '.png', bytes: 50 * 1024 },
];

/**
 * Files that never go through WordPress: the way an FTP upload or a
 * migration leaves them. sanitize_file_name() would strip these characters
 * or refuse these files, so they are copied straight into uploads/ and the
 * scan has to take them as they are.
 */
export const DISK_FIXTURES: Fixture[] = [
	{ name: 'empty-0b.txt', bytes: 0 },
	{ name: 'no-extension-file', bytes: 1500 },
	{ name: "plus+and&more'quote,comma.png", bytes: 40 * 1024 },
];

/** A valid PNG of roughly `bytes` bytes: random pixels, stored (level 0) deflate. */
export function png( bytes: number ): Buffer {
	const side = Math.max( 4, Math.floor( Math.sqrt( bytes / 3 ) ) );
	const raw = Buffer.alloc( ( side * 3 + 1 ) * side );
	for ( let y = 0; y < side; y++ ) {
		raw[ y * ( side * 3 + 1 ) ] = 0; // filter: none
		randomBytes( side * 3 ).copy( raw, y * ( side * 3 + 1 ) + 1 );
	}
	const chunk = ( type: string, data: Buffer ): Buffer => {
		const len = Buffer.alloc( 4 );
		len.writeUInt32BE( data.length );
		const body = Buffer.concat( [ Buffer.from( type, 'ascii' ), data ] );
		const crc = Buffer.alloc( 4 );
		crc.writeUInt32BE( crc32( body ) >>> 0 );
		return Buffer.concat( [ len, body, crc ] );
	};
	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( side, 0 );
	ihdr.writeUInt32BE( side, 4 );
	ihdr[ 8 ] = 8; // bit depth
	ihdr[ 9 ] = 2; // colour type: RGB
	return Buffer.concat( [
		Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] ),
		chunk( 'IHDR', ihdr ),
		chunk( 'IDAT', zlib.deflateSync( raw, { level: 0 } ) ),
		chunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}

let crcTable: Uint32Array | null = null;
function crc32( buf: Buffer ): number {
	if ( ! crcTable ) {
		crcTable = new Uint32Array( 256 );
		for ( let n = 0; n < 256; n++ ) {
			let c = n;
			for ( let k = 0; k < 8; k++ ) c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
			crcTable[ n ] = c >>> 0;
		}
	}
	let crc = 0xffffffff;
	for ( const b of buf ) crc = crcTable[ ( crc ^ b ) & 0xff ] ^ ( crc >>> 8 );
	return ( crc ^ 0xffffffff ) >>> 0;
}

/** An MP4 that finfo recognises (ftyp isom), followed by random bytes. */
export function mp4( bytes: number ): Buffer {
	const header = Buffer.from( '0000001c667479706973366d0000020069736f6d69736f326d703431', 'hex' );
	return Buffer.concat( [ header, randomBytes( bytes - header.length ) ] );
}

export function pdf( bytes: number ): Buffer {
	const head = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n';
	const tail = 'trailer<</Root 1 0 R>>\n%%EOF\n';
	const pad = '%' + 'x'.repeat( Math.max( 0, bytes - head.length - tail.length - 2 ) ) + '\n';
	return Buffer.from( head + pad + tail );
}

function txt( bytes: number ): Buffer {
	return Buffer.from( 'DiluxOne Offload real-suite fixture.\n'.repeat( Math.ceil( bytes / 37 ) ).slice( 0, bytes ) );
}

/** Generate the set on disk (idempotent). Returns absolute host paths. */
export function generateFixtures(): string[] {
	fs.mkdirSync( FIXTURE_DIR, { recursive: true } );
	return [ ...FIXTURES, ...DISK_FIXTURES ].map( ( f ) => {
		const file = path.join( FIXTURE_DIR, f.name );
		const ext = path.extname( f.name ).toLowerCase();
		try {
			// Create-only: a fixture already on disk is kept as it is.
			fs.writeFileSync( file, ext === '.png' ? png( f.bytes ) : ext === '.mp4' ? mp4( f.bytes ) : ext === '.pdf' ? pdf( f.bytes ) : txt( f.bytes ), { flag: 'wx' } );
		} catch ( e ) {
			if ( ( e as NodeJS.ErrnoException ).code !== 'EEXIST' ) throw e;
		}
		return file;
	} );
}

/** Copy the disk-only set into the site's current uploads subdirectory, bypassing WordPress. Returns their paths relative to uploads/. */
export function placeDiskFixtures( site: Site, uploadsDir: string, subdir: string, url?: string ): string[] {
	return DISK_FIXTURES.map( ( f ) => {
		// Double quotes: the names carry a single quote, an ampersand and spaces.
		shell( site, `cp "${ REPO_IN_CONTAINER }/build/real-fixtures/${ f.name }" "${ uploadsDir }${ subdir }/${ f.name }"` );
		return `${ subdir.replace( /^\//, '' ) }/${ f.name }`;
	} );
}

/** Import the set into a site's Media Library the way WP-CLI does. Returns attachment IDs. */
export function seedMediaLibrary( site: Site, names: string[] = FIXTURES.map( ( f ) => f.name ), url?: string ): number[] {
	const inside = names.map( ( n ) => `${ REPO_IN_CONTAINER }/build/real-fixtures/${ n }` );
	const out = wp( site, [ 'media', 'import', ...inside, '--porcelain' ], url );
	return out.split( '\n' ).map( ( s ) => Number( s.trim() ) ).filter( ( n ) => n > 0 );
}
