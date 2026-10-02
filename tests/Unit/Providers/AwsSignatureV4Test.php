<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\AwsSignatureV4;

/**
 * Unit tests for the SigV4 signer, against published vectors only.
 *
 * Two sources, neither written here: the S3 examples of the AWS
 * documentation (examplebucket, 24 May 2013), whose signatures botocore
 * reproduces too; and vectors of the AWS Signature Version 4 Test Suite
 * (Apache-2.0, copied under tests/fixtures/sigv4/ with their licence), each
 * asserting the canonical request, the string to sign and the header.
 */
class AwsSignatureV4Test extends TestCase {

	private const S3_KEY    = 'AKIAIOSFODNN7EXAMPLE';
	private const S3_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
	private const S3_TIME   = 1369353600; // 2013-05-24T00:00:00Z

	private const SUITE_KEY    = 'AKIDEXAMPLE';
	private const SUITE_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
	private const SUITE_DATE   = '20150830T123600Z';

	private function s3(): AwsSignatureV4 {
		return new AwsSignatureV4( self::S3_KEY, self::S3_SECRET, 'us-east-1' );
	}

	/** @return array<string,array{0:string,1:string,2:array<string,string>}> */
	public function s3_examples(): array {
		return array(
			'GET object with a range' => array( 'https://examplebucket.s3.amazonaws.com/test.txt', 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', array( 'Range' => 'bytes=0-9' ) ),
			'GET bucket lifecycle'    => array( 'https://examplebucket.s3.amazonaws.com/?lifecycle', 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', array() ),
			'GET bucket (list)'       => array( 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J', '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', array() ),
		);
	}

	/**
	 * @dataProvider s3_examples
	 * @param array<string,string> $headers
	 */
	public function test_the_s3_documentation_examples( string $url, string $signature, array $headers ): void {
		$signed = $this->s3()->sign( 'GET', $url, $headers, AwsSignatureV4::EMPTY_PAYLOAD, self::S3_TIME );

		$this->assertSame( '20130524T000000Z', $signed['x-amz-date'] );
		$this->assertSame( 'examplebucket.s3.amazonaws.com', $signed['Host'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=', $signed['Authorization'] );
		$this->assertStringEndsWith( 'Signature=' . $signature, $signed['Authorization'] );
	}

	/** @return array<string,array{0:string,1:string,2:array<string,string>,3:string}> */
	public function suite_vectors(): array {
		return array(
			'get-vanilla-empty-query-key'     => array( 'GET', 'https://example.amazonaws.com/?Param1=value1', array(), AwsSignatureV4::EMPTY_PAYLOAD ),
			'get-utf8'                        => array( 'GET', 'https://example.amazonaws.com/%E1%88%B4', array(), AwsSignatureV4::EMPTY_PAYLOAD ),
			'get-vanilla-query-order-encoded' => array( 'GET', 'https://example.amazonaws.com/?Param-3=Value3&Param=Value2&%E1%88%B4=Value1', array(), AwsSignatureV4::EMPTY_PAYLOAD ),
			'post-x-www-form-urlencoded'      => array( 'POST', 'https://example.amazonaws.com/', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), hash( 'sha256', 'Param1=value1' ) ),
			'get-header-value-trim'           => array(
				'GET',
				'https://example.amazonaws.com/',
				array(
					'My-Header1' => ' value1',
					'My-Header2' => ' "a   b   c"',
				),
				AwsSignatureV4::EMPTY_PAYLOAD,
			),
		);
	}

	/**
	 * @dataProvider suite_vectors
	 * @param array<string,string> $headers
	 */
	public function test_the_signature_v4_test_suite( string $method, string $url, array $headers, string $payload_hash ): void {
		$name    = (string) $this->dataName();
		$fixture = static function ( string $ext ) use ( $name ): string {
			return (string) file_get_contents( dirname( __DIR__, 2 ) . "/fixtures/sigv4/{$name}/{$name}.{$ext}" );
		};

		$headers = array(
			'Host'       => 'example.amazonaws.com',
			'X-Amz-Date' => self::SUITE_DATE,
		) + $headers;
		$signer  = new AwsSignatureV4( self::SUITE_KEY, self::SUITE_SECRET, 'us-east-1', 'service' );
		$creq    = $signer->canonical_request( $method, $url, $headers, $payload_hash );

		$this->assertSame( $fixture( 'creq' ), $creq );
		$this->assertSame( $fixture( 'sts' ), $signer->string_to_sign( $creq, self::SUITE_DATE ) );
		$this->assertSame( $fixture( 'authz' ), $signer->authorization( $method, $url, $headers, $payload_hash ) );
	}

	public function test_an_empty_query_value_keeps_its_equals_sign(): void {
		$creq = $this->s3()->canonical_request( 'POST', 'https://b.s3.amazonaws.com/a.jpg?uploads', array( 'Host' => 'b.s3.amazonaws.com' ), AwsSignatureV4::UNSIGNED_PAYLOAD );
		$this->assertSame( 'uploads=', explode( "\n", $creq )[2] );
	}

	public function test_query_pairs_sort_by_name_then_value_and_are_encoded_once(): void {
		$creq = $this->s3()->canonical_request( 'GET', 'https://b.s3.amazonaws.com/?prefix=a%20b%2Bc&list-type=2&continuation-token=x%2Fy%3D', array( 'Host' => 'b.s3.amazonaws.com' ), AwsSignatureV4::UNSIGNED_PAYLOAD );
		$this->assertSame( 'continuation-token=x%2Fy%3D&list-type=2&prefix=a%20b%2Bc', explode( "\n", $creq )[2] );
	}

	public function test_an_encoded_path_is_signed_as_sent_and_never_encoded_twice(): void {
		$creq = $this->s3()->canonical_request( 'PUT', 'https://s3.example.com/bucket/uploads/a%20b%2Bc%C3%B1.jpg', array( 'Host' => 's3.example.com' ), AwsSignatureV4::UNSIGNED_PAYLOAD );
		$this->assertSame( '/bucket/uploads/a%20b%2Bc%C3%B1.jpg', explode( "\n", $creq )[1] );
	}

	public function test_sign_signs_host_amz_and_content_headers_and_leaves_the_rest_unsigned(): void {
		$signed = $this->s3()->sign(
			'PUT',
			'https://b.s3.amazonaws.com/k',
			array(
				'Content-Type'   => 'image/jpeg',
				'Content-Length' => '10',
				'x-amz-acl'      => 'public-read',
			),
			AwsSignatureV4::UNSIGNED_PAYLOAD,
			self::S3_TIME
		);

		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-acl;x-amz-content-sha256;x-amz-date,', $signed['Authorization'] );
		$this->assertSame( '10', $signed['Content-Length'] );
		$this->assertSame( 'UNSIGNED-PAYLOAD', $signed['x-amz-content-sha256'] );
	}

	public function test_the_secret_appears_in_no_header(): void {
		$signed = $this->s3()->sign( 'GET', 'https://b.s3.amazonaws.com/k' );
		foreach ( $signed as $value ) {
			$this->assertStringNotContainsString( self::S3_SECRET, $value );
		}
	}

	/** @return array<string,array{0:string,1:string}> */
	public function hosts(): array {
		return array(
			'https default port' => array( 'https://s3.example.com:443/b', 's3.example.com' ),
			'http default port'  => array( 'http://minio/b', 'minio' ),
			'MinIO port'         => array( 'http://minio:9000/e2e/k', 'minio:9000' ),
			'upper-case host'    => array( 'https://S3.Example.COM/b', 's3.example.com' ),
		);
	}

	/** @dataProvider hosts */
	public function test_the_host_header_carries_a_non_default_port( string $url, string $host ): void {
		$this->assertSame( $host, AwsSignatureV4::host( $url ) );
	}

	public function test_empty_pairs_in_a_query_are_left_out_of_the_canonical_query(): void {
		$creq = $this->s3()->canonical_request( 'GET', 'https://s3.example.com/b/?prefix=a&&uploads=&', array( 'Host' => 's3.example.com' ), AwsSignatureV4::UNSIGNED_PAYLOAD );
		$this->assertSame( 'prefix=a&uploads=', explode( "\n", $creq )[2] );
	}
}
