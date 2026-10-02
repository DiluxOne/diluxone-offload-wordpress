<?php
namespace Tests\Unit\Support;

/**
 * A local file that misbehaves on cue: a stream wrapper (faulty://name)
 * whose stat, open, seek and read answers a test scripts.
 *
 * It stands for the disk failures a real file system only produces under
 * load or by accident: a read error halfway through a file, a file that is
 * gone between the check and the read, a handle that cannot seek, a file
 * that ends before the size it reported. The code under test opens these
 * paths with the same fopen()/fread()/filesize() it uses on uploads/.
 *
 * Per file (FaultyStream::$files['name']):
 * - content:          the bytes reads return (default '');
 * - size:             the size stat reports (default strlen(content));
 * - stats_ok:         how many stat calls succeed before stat fails (null: all);
 * - opens_ok:         how many opens succeed before open fails (null: all);
 * - reads_ok:         how many reads succeed before a read returns false (null: all);
 * - seek_fails:       every seek fails;
 * - never_eof:        the stream never reports its end (reads past the content return '').
 */
class FaultyStream {

	public const SCHEME = 'faulty';

	/** @var array<string, array<string, mixed>> */
	public static array $files = array();

	/** @var array<string, array{stats: int, opens: int, reads: int}> Calls per file. */
	public static array $calls = array();

	/** @var resource|null */
	public $context;

	private string $name = '';
	private int $pos     = 0;

	public static function register(): void {
		if ( in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( self::SCHEME );
		}
		stream_wrapper_register( self::SCHEME, self::class );
		self::$files = array();
		self::$calls = array();
	}

	public static function unregister(): void {
		if ( in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( self::SCHEME );
		}
		self::$files = array();
		self::$calls = array();
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return string The path to open.
	 */
	public static function file( string $name, array $spec ): string {
		self::$files[ $name ] = $spec;
		self::$calls[ $name ] = array( 'stats' => 0, 'opens' => 0, 'reads' => 0 );
		return self::SCHEME . '://' . $name;
	}

	private static function name_of( string $path ): string {
		return (string) substr( $path, strlen( self::SCHEME . '://' ) );
	}

	/** @return array<string, mixed> */
	private function spec(): array {
		return self::$files[ $this->name ] ?? array();
	}

	private function content(): string {
		return (string) ( $this->spec()['content'] ?? '' );
	}

	/** @return array<int|string, int> */
	private static function stat_of( array $spec ): array {
		$size = (int) ( $spec['size'] ?? strlen( (string) ( $spec['content'] ?? '' ) ) );
		$mode = 0100644;
		return array( 2 => $mode, 7 => $size, 'mode' => $mode, 'size' => $size );
	}

	/** @return array<int|string, int>|false */
	public function url_stat( string $path, int $flags ) {
		$name = self::name_of( $path );
		if ( ! isset( self::$files[ $name ] ) ) {
			return false;
		}
		++self::$calls[ $name ]['stats'];
		$ok = self::$files[ $name ]['stats_ok'] ?? null;
		if ( null !== $ok && self::$calls[ $name ]['stats'] > $ok ) {
			return false;
		}
		return self::stat_of( self::$files[ $name ] );
	}

	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		$this->name = self::name_of( $path );
		if ( ! isset( self::$files[ $this->name ] ) ) {
			return false;
		}
		++self::$calls[ $this->name ]['opens'];
		$ok = $this->spec()['opens_ok'] ?? null;
		return null === $ok || self::$calls[ $this->name ]['opens'] <= $ok;
	}

	/** @return string|false */
	public function stream_read( int $count ) {
		++self::$calls[ $this->name ]['reads'];
		$ok = $this->spec()['reads_ok'] ?? null;
		if ( null !== $ok && self::$calls[ $this->name ]['reads'] > $ok ) {
			return false;
		}
		$chunk      = (string) substr( $this->content(), $this->pos, $count );
		$this->pos += strlen( $chunk );
		return $chunk;
	}

	public function stream_eof(): bool {
		if ( ! empty( $this->spec()['never_eof'] ) ) {
			return false;
		}
		return $this->pos >= strlen( $this->content() );
	}

	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool {
		if ( ! empty( $this->spec()['seek_fails'] ) ) {
			return false;
		}
		$this->pos = $offset;
		return true;
	}

	public function stream_tell(): int {
		return $this->pos;
	}

	/** @return array<int|string, int> */
	public function stream_stat(): array {
		return self::stat_of( $this->spec() );
	}

	public function stream_close(): void {
	}

	/** Lets stream_select()/stream_set_* calls fall through without a warning. */
	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): bool {
		return false;
	}
}
