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
