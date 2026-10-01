<?php
/**
 * A small S3-compatible server for the end-to-end suite, so every screen can
 * be driven through a real provider without a cloud account.
 *
 * The suite copies this file and its .htaccess to /e2e-bucket/ on the dev
 * site; the plugin is then configured with the Custom S3 service, endpoint
 * http://localhost:8888 and bucket e2e-bucket, path-style. The plugin's own
 * S3 provider talks to it exactly as it talks to a real service (SigV4
 * headers, ListObjectsV2, multipart, CopyObject), from the WordPress HTTP
 * API and from the sync's curl_multi pool alike. Signatures are not checked.
 *
 * The objects live on disk under wp-content/e2e-s3/ (outside the URL path,
 * so every request reaches this script). A few control requests let a test
 * read the bucket and make it fail on purpose:
 *
 *   GET  /e2e-bucket/?e2e=list            JSON: every object, key => [size, md5]
 *   POST /e2e-bucket/?e2e=reset           empty the bucket and drop the rules
 *   POST /e2e-bucket/?e2e=rules           body: JSON list of rules, each
 *        {"method":"PUT","match":"broken","status":400,"code":"BadDigest"}
 *        ("method" and "match" optional: every method, every key).
 *   POST /e2e-bucket/?e2e=put&key=<k>     store the body under <k> (a file
 *        someone else put there).
 *
 * phpcs:ignoreFile -- a test fixture, never shipped (tests/ is in .distignore).
 */

$bucket = 'e2e-bucket';
$root   = dirname( __DIR__ ) . '/wp-content/e2e-s3';
$data   = $root . '/objects';
$mpu    = $root . '/multipart';
$rules  = $root . '/rules.json';

foreach ( array( $data, $mpu ) as $dir ) {
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = (string) parse_url( $uri, PHP_URL_PATH );
$query  = array();
parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $query );

// The key: everything after /e2e-bucket/, each segment decoded once.
$prefix = '/' . $bucket . '/';
$key    = 0 === strpos( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : '';
$key    = implode( '/', array_map( 'rawurldecode', explode( '/', $key ) ) );

function e2e_xml( int $status, string $body ): void {
	http_response_code( $status );
	header( 'Content-Type: application/xml' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $body;
	exit;
}

function e2e_error( int $status, string $code, string $message ): void {
	e2e_xml( $status, '<Error><Code>' . htmlspecialchars( $code ) . '</Code><Message>' . htmlspecialchars( $message ) . '</Message><RequestId>e2e</RequestId></Error>' );
}

function e2e_file( string $data, string $key ): string {
	return $data . '/' . $key;
}

/** Every object under the data directory: key => absolute path, in key order. */
function e2e_objects( string $data ): array {
	$out = array();
	if ( ! is_dir( $data ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $data, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() ) {
			$out[ substr( $file->getPathname(), strlen( $data ) + 1 ) ] = $file->getPathname();
		}
	}
	ksort( $out, SORT_STRING );
	return $out;
}

function e2e_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $dir );
}

function e2e_etag( string $file ): string {
	return '"' . md5_file( $file ) . '"';
}

function e2e_date( string $file ): string {
	return gmdate( 'Y-m-d\TH:i:s.000\Z', (int) filemtime( $file ) );
}

// ── Control requests from the test runner ──
if ( isset( $query['e2e'] ) ) {
	header( 'Content-Type: application/json' );
	switch ( $query['e2e'] ) {
		case 'list':
			$list = array();
			foreach ( e2e_objects( $data ) as $k => $file ) {
				$list[ $k ] = array( filesize( $file ), md5_file( $file ) );
			}
			echo json_encode( (object) $list );
			exit;
		case 'reset':
			e2e_rmdir( $data );
			e2e_rmdir( $mpu );
			@unlink( $rules );
			echo '{"ok":true}';
			exit;
		case 'rules':
			file_put_contents( $rules, (string) file_get_contents( 'php://input' ) );
			echo '{"ok":true}';
			exit;
		case 'put':
			$target = e2e_file( $data, (string) ( $query['key'] ?? '' ) );
			@mkdir( dirname( $target ), 0777, true );
			file_put_contents( $target, (string) file_get_contents( 'php://input' ) );
			echo '{"ok":true}';
			exit;
	}
	http_response_code( 400 );
	echo '{"ok":false}';
	exit;
}

// ── Failures a test asked for ──
if ( is_file( $rules ) ) {
	foreach ( (array) json_decode( (string) file_get_contents( $rules ), true ) as $rule ) {
		$by_method = empty( $rule['method'] ) || strtoupper( $rule['method'] ) === $method;
		$by_key    = empty( $rule['match'] ) || false !== strpos( $key, (string) $rule['match'] );
		if ( $by_method && $by_key ) {
			file_get_contents( 'php://input' ); // Drain the body, as a server would.
			e2e_error( (int) ( $rule['status'] ?? 500 ), (string) ( $rule['code'] ?? 'InternalError' ), (string) ( $rule['message'] ?? 'Refused by the e2e rules' ) );
		}
	}
}

// ── Bucket-level requests ──
if ( '' === $key ) {
	if ( 'GET' === $method && isset( $query['uploads'] ) ) {
		$xml = '<ListMultipartUploadsResult><Bucket>' . $bucket . '</Bucket><IsTruncated>false</IsTruncated>';
		foreach ( glob( $mpu . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			$upload_key = (string) @file_get_contents( $dir . '/key' );
			if ( '' === ( $query['prefix'] ?? '' ) || 0 === strpos( $upload_key, (string) $query['prefix'] ) ) {
				$xml .= '<Upload><Key>' . htmlspecialchars( $upload_key ) . '</Key><UploadId>' . basename( $dir ) . '</UploadId></Upload>';
			}
		}
		e2e_xml( 200, $xml . '</ListMultipartUploadsResult>' );
	}
	if ( 'GET' === $method ) {
		$list_prefix = (string) ( $query['prefix'] ?? '' );
		$after       = (string) ( $query['continuation-token'] ?? ( $query['start-after'] ?? '' ) );
		$max         = max( 1, min( 1000, (int) ( $query['max-keys'] ?? 1000 ) ) );
		$keys        = array();
		foreach ( e2e_objects( $data ) as $k => $file ) {
			if ( ( '' === $list_prefix || 0 === strpos( $k, $list_prefix ) ) && ( '' === $after || strcmp( $k, $after ) > 0 ) ) {
				$keys[ $k ] = $file;
			}
		}
		$page      = array_slice( $keys, 0, $max, true );
		$truncated = count( $keys ) > $max;
		$xml       = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>' . $bucket . '</Name><Prefix>' . htmlspecialchars( $list_prefix ) . '</Prefix><KeyCount>' . count( $page ) . '</KeyCount><MaxKeys>' . $max . '</MaxKeys><IsTruncated>' . ( $truncated ? 'true' : 'false' ) . '</IsTruncated>';
		foreach ( $page as $k => $file ) {
			$xml .= '<Contents><Key>' . htmlspecialchars( $k ) . '</Key><LastModified>' . e2e_date( $file ) . '</LastModified><ETag>' . htmlspecialchars( e2e_etag( $file ) ) . '</ETag><Size>' . filesize( $file ) . '</Size><StorageClass>STANDARD</StorageClass></Contents>';
		}
		if ( $truncated ) {
			$xml .= '<NextContinuationToken>' . htmlspecialchars( (string) array_key_last( $page ) ) . '</NextContinuationToken>';
		}
		e2e_xml( 200, $xml . '</ListBucketResult>' );
	}
	e2e_error( 405, 'MethodNotAllowed', 'Not on the bucket' );
}

$file = e2e_file( $data, $key );

// ── Multipart ──
if ( 'POST' === $method && isset( $query['uploads'] ) ) {
	$id = bin2hex( random_bytes( 12 ) );
	mkdir( $mpu . '/' . $id, 0777, true );
	file_put_contents( $mpu . '/' . $id . '/key', $key );
	e2e_xml( 200, '<InitiateMultipartUploadResult><Bucket>' . $bucket . '</Bucket><Key>' . htmlspecialchars( $key ) . '</Key><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>' );
}
if ( isset( $query['uploadId'] ) ) {
	$dir = $mpu . '/' . preg_replace( '/[^a-f0-9]/', '', (string) $query['uploadId'] );
	if ( ! is_dir( $dir ) ) {
		file_get_contents( 'php://input' );
		e2e_error( 404, 'NoSuchUpload', 'The specified upload does not exist.' );
	}
	if ( 'PUT' === $method ) {
		$part = (int) ( $query['partNumber'] ?? 0 );
		file_put_contents( $dir . '/' . $part . '.part', (string) file_get_contents( 'php://input' ) );
		header( 'ETag: ' . e2e_etag( $dir . '/' . $part . '.part' ) );
		http_response_code( 200 );
		exit;
	}
	if ( 'GET' === $method ) {
		$xml = '<ListPartsResult><Bucket>' . $bucket . '</Bucket><Key>' . htmlspecialchars( $key ) . '</Key><UploadId>' . basename( $dir ) . '</UploadId><IsTruncated>false</IsTruncated>';
		foreach ( glob( $dir . '/*.part' ) ?: array() as $part_file ) {
			$xml .= '<Part><PartNumber>' . (int) basename( $part_file, '.part' ) . '</PartNumber><ETag>' . htmlspecialchars( e2e_etag( $part_file ) ) . '</ETag><Size>' . filesize( $part_file ) . '</Size></Part>';
		}
		e2e_xml( 200, $xml . '</ListPartsResult>' );
	}
	if ( 'POST' === $method ) {
		file_get_contents( 'php://input' );
		$parts = glob( $dir . '/*.part' ) ?: array();
		usort( $parts, static fn( $a, $b ) => (int) basename( $a, '.part' ) <=> (int) basename( $b, '.part' ) );
		@mkdir( dirname( $file ), 0777, true );
		$out = fopen( $file, 'wb' );
		foreach ( $parts as $part_file ) {
			fwrite( $out, (string) file_get_contents( $part_file ) );
		}
		fclose( $out );
		e2e_rmdir( $dir );
		e2e_xml( 200, '<CompleteMultipartUploadResult><Bucket>' . $bucket . '</Bucket><Key>' . htmlspecialchars( $key ) . '</Key><ETag>"' . md5( (string) count( $parts ) ) . '-' . count( $parts ) . '"</ETag></CompleteMultipartUploadResult>' );
	}
	if ( 'DELETE' === $method ) {
		e2e_rmdir( $dir );
		http_response_code( 204 );
		exit;
	}
}

// ── Objects ──
switch ( $method ) {
	case 'PUT':
		$source = $_SERVER['HTTP_X_AMZ_COPY_SOURCE'] ?? '';
		@mkdir( dirname( $file ), 0777, true );
		if ( '' !== $source ) {
			$from = rawurldecode( preg_replace( '#^/?' . preg_quote( $bucket, '#' ) . '/#', '', $source ) );
			$from = implode( '/', array_map( 'rawurldecode', explode( '/', $from ) ) );
			if ( ! is_file( e2e_file( $data, $from ) ) ) {
				e2e_error( 404, 'NoSuchKey', 'The specified key does not exist.' );
			}
			copy( e2e_file( $data, $from ), $file );
			e2e_xml( 200, '<CopyObjectResult><LastModified>' . e2e_date( $file ) . '</LastModified><ETag>' . e2e_etag( $file ) . '</ETag></CopyObjectResult>' );
		}
		$in  = fopen( 'php://input', 'rb' );
		$out = fopen( $file, 'wb' );
		stream_copy_to_stream( $in, $out );
		fclose( $out );
		header( 'ETag: ' . e2e_etag( $file ) );
		http_response_code( 200 );
		exit;

	case 'GET':
	case 'HEAD':
		if ( ! is_file( $file ) ) {
			if ( 'HEAD' === $method ) {
				http_response_code( 404 );
				exit;
			}
			e2e_error( 404, 'NoSuchKey', 'The specified key does not exist.' );
		}
		$ext   = strtolower( pathinfo( $key, PATHINFO_EXTENSION ) );
		$types = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain', 'pdf' => 'application/pdf', 'mp4' => 'video/mp4' );
		header( 'Content-Type: ' . ( $types[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'ETag: ' . e2e_etag( $file ) );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) filemtime( $file ) ) . ' GMT' );
		http_response_code( 200 );
		if ( 'GET' === $method ) {
			readfile( $file );
		}
		exit;

	case 'DELETE':
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		http_response_code( 204 );
		exit;
}

e2e_error( 405, 'MethodNotAllowed', 'Unsupported request' );
