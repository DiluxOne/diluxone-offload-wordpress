/**
 * Whether an object holds the bytes whose MD5 is `expected`, from what the
 * suite read of it: no SDK, no I/O, so the end-to-end suite tests every
 * case without a cloud account (tests/E2E/byte-verdict.spec.ts).
 *
 * An MD5 that matches cannot come from wrong bytes, so any of three is
 * enough: the first read, the MD5 the service lists for what it stores
 * (Google only, where reads can come from its cache), or a second read a
 * little later. When none matches, the object itself differs, and the
 * message says what was read, read again, listed and what the service says
 * about the object. It never turns a mismatch into a pass.
 */
export interface ByteReads {
	/** MD5 of the bytes the object must hold. */
	expected: string;
	/** MD5 of a download of the object. */
	first: string;
	/** MD5 of a download a little later ('' when not read yet). */
	second?: string;
	/** MD5 the service's listing gives for what it stores ('' when not asked or not an MD5). */
	stored?: string;
	/** What the service says about the object, for the message. */
	facts?: string;
}

/** '' when the object is right; otherwise why it is not. */
export function byteVerdict( { expected, first, second = '', stored = '', facts = '' }: ByteReads ): string {
	// A read that gave nothing (a missing file's md5sum) matches nothing.
	if ( expected && [ first, stored, second ].includes( expected ) ) return '';
	const listed = stored ? `, listed ${ stored }` : '';
	return `expected md5 ${ expected }, read ${ first }, read again ${ second || '(not read)' }${ listed } (the object itself differs); ${ facts }`;
}
