<?php
/**
 * A namespaced stand-in for wp_tempnam(), so a test can hand the stream
 * wrapper a temp "file" it cannot write: what a full or read-only temp
 * directory looks like. Passes through to the real one unless
 * $GLOBALS['_dlx_tempnam_answer'] is set.
 *
 * Required only inside tests that run in their own process (see
 * crypto-faults.php for why).
 */

namespace DiluxOneOffload;

function wp_tempnam( string $filename = '', string $dir = '' ): string {
	if ( ! empty( $GLOBALS['_dlx_tempnam_answer'] ) ) {
		return (string) $GLOBALS['_dlx_tempnam_answer'];
	}
	return \wp_tempnam( $filename, $dir );
}
