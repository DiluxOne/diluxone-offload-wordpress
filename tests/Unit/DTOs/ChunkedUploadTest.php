<?php
namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\ChunkedUpload;

/**
 * The parts a large file makes, the tags that let it be committed, and the
 * token its row keeps so a later request can take it up.
 */
class ChunkedUploadTest extends TestCase {

	public function test_parts_cover_the_file_and_the_last_one_carries_the_rest(): void {
		$u = new ChunkedUpload( '/f', 'uploads/f', 2500, 1000, 'U' );
		$this->assertSame( 3, $u->partCount() );
		$this->assertSame( array( 0, 1000, 2000 ), array( $u->offset( 1 ), $u->offset( 2 ), $u->offset( 3 ) ) );
		$this->assertSame( array( 1000, 1000, 500 ), array( $u->length( 1 ), $u->length( 2 ), $u->length( 3 ) ) );
		$this->assertSame( 1, ( new ChunkedUpload( '/f', 'f', 1000, 1000 ) )->partCount(), 'a file of exactly one part' );
	}

	public function test_the_commit_waits_for_every_tag(): void {
		$u = new ChunkedUpload( '/f', 'f', 2500, 1000 );
		$u->recordTag( 3, 'c' );
		$u->recordTag( 1, 'a' );
		$this->assertNull( $u->tags() );
		$this->assertSame( array( 2 ), $u->missingParts() );
		$u->recordTag( 2, 'b' );
		$this->assertSame( array( 1 => 'a', 2 => 'b', 3 => 'c' ), $u->tags(), 'in part order' );
		$this->assertSame( array(), $u->missingParts() );
	}

	public function test_a_size_or_part_size_of_zero_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		new ChunkedUpload( '/f', 'f', 0, 1000 );
	}

	public function test_the_token_takes_the_upload_up_only_for_the_same_file(): void {
		$token = ( new ChunkedUpload( '/f', 'f', 2500, 1000, 'id|with|pipes' ) )->resumeToken( 1700000000 );
		$this->assertSame( 'id|with|pipes', ChunkedUpload::resumableUploadId( $token, 2500, 1700000000 ) );
		$this->assertNull( ChunkedUpload::resumableUploadId( $token, 2501, 1700000000 ), 'another size' );
		$this->assertNull( ChunkedUpload::resumableUploadId( $token, 2500, 1700000001 ), 'modified since' );
		$this->assertSame( '', ChunkedUpload::resumableUploadId( ( new ChunkedUpload( '/f', 'f', 2500, 1000 ) )->resumeToken( 5 ), 2500, 5 ), 'a provider that names no upload' );
	}

	/** An upload name too long for the row's 255 characters (R2's are) is kept as its SHA-1. */
	public function test_a_long_upload_name_is_kept_as_its_sha1(): void {
		$name  = str_repeat( 'R2', 150 );
		$token = ( new ChunkedUpload( '/f', 'f', 2500, 1000, $name ) )->resumeToken( 1700000000 );
		$this->assertLessThanOrEqual( 255, strlen( $token ) );
		$this->assertSame( '#' . sha1( $name ), ChunkedUpload::resumableUploadId( $token, 2500, 1700000000 ) );
		$short = str_repeat( 'a', ChunkedUpload::MAX_TOKEN_UPLOAD_ID );
		$this->assertSame( $short, ChunkedUpload::uploadIdOf( ( new ChunkedUpload( '/f', 'f', 2500, 1000, $short ) )->resumeToken( 1 ) ) );
	}

	/** @dataProvider notTokens */
	public function test_anything_else_in_the_column_starts_over( ?string $value ): void {
		$this->assertNull( ChunkedUpload::resumableUploadId( $value, 2500, 5 ) );
		$this->assertNull( ChunkedUpload::uploadIdOf( $value ) );
	}

	/** @return array<string, array{0: ?string}> */
	public function notTokens(): array {
		return array(
			'empty'         => array( null ),
			'blank'         => array( '' ),
			'a bare id'     => array( 'block-list-abc' ),
			'other version' => array( 'v2|2500|5|U' ),
		);
	}
}
