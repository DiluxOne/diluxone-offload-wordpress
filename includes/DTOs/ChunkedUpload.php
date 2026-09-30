<?php
/**
 * A large file's upload in parts, while its parts are in flight.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunked Upload DTO
 *
 * What the sync and the provider share while a large file's parts go through
 * the sync's pool: the file, the provider's name for the upload (an S3
 * UploadId; empty for Azure, whose blocks need none) and the tag each part
 * got back (an S3 ETag, an Azure block id), which the commit lists in order.
 * An upload a request did not finish is taken up by the next one: the row
 * keeps resumeToken(), and the provider tags the parts that already landed.
 *
 * @package DiluxOneOffload\DTOs
 * @since 2.1.0
 */
class ChunkedUpload {

	/** @var string */
	private string $localPath;
	/** @var string */
	private string $remotePath;
	/** @var int */
	private int $size;
	/** @var int */
	private int $partSize;
	/** @var string */
	private string $uploadId;
	/** @var array<int, string> Tag per part number. */
	private array $tags = array();

	/**
	 * Constructor
	 *
	 * @param string $localPath  The file under uploads/.
	 * @param string $remotePath Its key in the container or bucket.
	 * @param int    $size       Its size in bytes.
	 * @param int    $partSize   Bytes per part; the last part carries the rest.
	 * @param string $uploadId   The provider's name for the upload, '' when it needs none.
	 *
	 * @throws \InvalidArgumentException When the size or the part size is not positive.
	 */
	public function __construct( string $localPath, string $remotePath, int $size, int $partSize, string $uploadId = '' ) {
		if ( $size <= 0 || $partSize <= 0 ) {
			throw new \InvalidArgumentException( 'A chunked upload needs a positive size and part size' );
		}
		$this->localPath  = $localPath;
		$this->remotePath = $remotePath;
		$this->size       = $size;
		$this->partSize   = $partSize;
		$this->uploadId   = $uploadId;
	}

	/** @return string */
	public function localPath(): string {
		return $this->localPath;
	}

	/** @return string */
	public function remotePath(): string {
		return $this->remotePath;
	}

	/** @return int */
	public function size(): int {
		return $this->size;
	}

	/** @return string */
	public function uploadId(): string {
		return $this->uploadId;
	}

	/**
	 * How many parts the file makes.
	 *
	 * @return int
	 */
	public function partCount(): int {
		return (int) ceil( $this->size / $this->partSize );
	}

	/**
	 * Where a part starts in the file (parts are numbered from 1).
	 *
	 * @param int $part Part number.
	 * @return int
	 */
	public function offset( int $part ): int {
		return ( $part - 1 ) * $this->partSize;
	}

	/**
	 * How many bytes a part carries.
	 *
	 * @param int $part Part number.
	 * @return int
	 */
	public function length( int $part ): int {
		return min( $this->partSize, $this->size - $this->offset( $part ) );
	}

	/**
	 * Record the tag the service gave a part.
	 *
	 * @param int    $part Part number.
	 * @param string $tag  ETag or block id.
	 */
	public function recordTag( int $part, string $tag ): void {
		$this->tags[ $part ] = $tag;
	}

	/**
	 * The tag of one part, '' until it was recorded.
	 *
	 * @param int $part Part number.
	 * @return string
	 */
	public function tag( int $part ): string {
		return $this->tags[ $part ] ?? '';
	}

	/**
	 * The parts that still have to be sent, in order.
	 *
	 * @return int[]
	 */
	public function missingParts(): array {
		$missing = array();
		$count   = $this->partCount();
		for ( $part = 1; $part <= $count; $part++ ) {
			if ( '' === $this->tag( $part ) ) {
				$missing[] = $part;
			}
		}
		return $missing;
	}

	/**
	 * What the sync keeps in the file's row to take the upload up again in
	 * a later request: the file's size and modification time, so a file that
	 * changed since is started over, and the provider's name for the upload.
	 * Which parts landed is asked of the service when it is taken up.
	 *
	 * @param int $mtime The file's modification time.
	 * @return string
	 */
	public function resumeToken( int $mtime ): string {
		return 'v1|' . $this->size . '|' . $mtime . '|' . $this->uploadId;
	}

	/**
	 * The upload a row's token names, if it still describes the same file.
	 *
	 * @param string|null $token What resumeToken() returned, or null.
	 * @param int         $size  The file's size now.
	 * @param int         $mtime The file's modification time now.
	 * @return string|null The provider's name for the upload ('' for one that needs none); null to start over.
	 */
	public static function resumableUploadId( ?string $token, int $size, int $mtime ): ?string {
		$fields = explode( '|', (string) $token, 4 );
		if ( null === self::uploadIdOf( $token ) || (string) $size !== $fields[1] || (string) $mtime !== $fields[2] ) {
			return null;
		}
		return $fields[3];
	}

	/**
	 * The provider's name for the upload a token keeps, whatever the file is
	 * like now (to drop the upload); null when it is not a token.
	 *
	 * @param string|null $token What resumeToken() returned, or null.
	 * @return string|null
	 */
	public static function uploadIdOf( ?string $token ): ?string {
		$fields = explode( '|', (string) $token, 4 );
		return 4 === count( $fields ) && 'v1' === $fields[0] ? $fields[3] : null;
	}

	/**
	 * Every part's tag in part order, or null while a part has none.
	 *
	 * @return array<int, string>|null
	 */
	public function tags(): ?array {
		$tags  = array();
		$count = $this->partCount();
		for ( $part = 1; $part <= $count; $part++ ) {
			if ( '' === $this->tag( $part ) ) {
				return null;
			}
			$tags[ $part ] = $this->tag( $part );
		}
		return $tags;
	}
}
