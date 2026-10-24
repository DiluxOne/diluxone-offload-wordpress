import { test, expect } from '@playwright/test';
import { byteVerdict } from '../E2E-real/helpers/byte-verdict';

/**
 * The verdict the real-storage suite's bytesDiffer() gives, on what it read:
 * a read served from Google's cache (an earlier run's copy, same name, other
 * bytes) must not fail a right object, and a wrong object must still fail,
 * saying what was read. No browser, no WordPress, no cloud.
 */
const EXPECTED = '79dc0000000000000000000000000000';
const STALE = '76280000000000000000000000000000';
const WRONG = 'abbb0000000000000000000000000000';
const FACTS = 'size 1048652, ETag "4725", last modified 2026-10-23T10:00:00.000Z; listed ETag "4725", last modified 2026-10-23T10:00:00.000Z';

test.describe( 'byteVerdict', () => {
	test( 'a first read that matches is enough', () => {
		expect( byteVerdict( { expected: EXPECTED, first: EXPECTED } ) ).toBe( '' );
	} );

	test( 'a stale first read passes when the listed MD5 matches', () => {
		expect( byteVerdict( { expected: EXPECTED, first: STALE, stored: EXPECTED } ) ).toBe( '' );
	} );

	test( 'a stale first read passes when the second read matches', () => {
		expect( byteVerdict( { expected: EXPECTED, first: STALE, second: EXPECTED, stored: STALE, facts: FACTS } ) ).toBe( '' );
	} );

	test( 'an object whose listed MD5 and both reads differ fails with what was read and the facts', () => {
		const verdict = byteVerdict( { expected: EXPECTED, first: STALE, second: WRONG, stored: WRONG, facts: FACTS } );
		expect( verdict ).not.toBe( '' );
		expect( verdict ).toContain( `expected md5 ${ EXPECTED }` );
		expect( verdict ).toContain( `read ${ STALE }` );
		expect( verdict ).toContain( `read again ${ WRONG }` );
		expect( verdict ).toContain( `listed ${ WRONG }` );
		expect( verdict ).toContain( 'the object itself differs' );
		expect( verdict ).toContain( FACTS );
	} );

	test( 'without a listed MD5 (not Google, or not an MD5) two wrong reads fail', () => {
		const verdict = byteVerdict( { expected: EXPECTED, first: WRONG, second: WRONG, facts: 'size 10240' } );
		expect( verdict ).toContain( `read ${ WRONG }, read again ${ WRONG } (the object itself differs); size 10240` );
		expect( verdict ).not.toContain( 'listed' );
	} );

	test( 'an empty read never matches an expected MD5', () => {
		expect( byteVerdict( { expected: EXPECTED, first: '' } ) ).not.toBe( '' );
		// An expected MD5 that is empty (the file was not there) is not met by reads that gave nothing.
		expect( byteVerdict( { expected: '', first: STALE } ) ).not.toBe( '' );
	} );
} );
