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
const FACTS = 'size 1048652, ETag "7628", last modified 2026-10-23T10:00:00.000Z; listed ETag "79dc", last modified 2026-10-24T10:00:00.000Z';

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

	test( 'an empty read never matches an expected MD5', () => {
		expect( byteVerdict( { expected: EXPECTED, first: '' } ) ).not.toBe( '' );
		// An expected MD5 that is empty (the file was not there) is not met by reads that gave nothing.
		expect( byteVerdict( { expected: '', first: STALE } ) ).not.toBe( '' );
	} );
} );
