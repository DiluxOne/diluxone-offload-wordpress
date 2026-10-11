<?php
namespace Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * The provider selector names its S3 option "S3-compatible storage", without
 * the list of providers that made the open dropdown wider than the page.
 * Every bundled translation, the .po and the .mo WordPress actually loads,
 * translates that short string, and none keeps the old long one.
 */
class TranslationsTest extends TestCase {

	private const LABEL = 'S3-compatible storage';

	private const OLD_LABEL = 'S3-compatible storage (Amazon S3, Cloudflare R2, Backblaze B2, Google Cloud Storage, Hetzner, Scaleway, OVHcloud, MinIO, …)';

	/** @return array<string, array{string}> */
	public function locales(): array {
		$locales = [];
		foreach ( glob( dirname( __DIR__, 3 ) . '/languages/diluxone-offload-*.po' ) ?: [] as $po ) {
			$locale             = (string) preg_replace( '/^diluxone-offload-(.+)\.po$/', '$1', basename( $po ) );
			$locales[ $locale ] = [ $locale ];
		}
		return $locales;
	}

	public function test_spanish_is_among_the_bundled_translations(): void {
		$this->assertArrayHasKey( 'es_ES', $this->locales() );
	}

	/** @dataProvider locales */
	public function test_the_po_translates_the_short_label_and_drops_the_long_one( string $locale ): void {
		$entries = $this->read_po( $this->path( $locale, 'po' ) );
		$this->assertArrayHasKey( self::LABEL, $entries );
		$this->assertShortTranslation( $entries[ self::LABEL ], $locale );
		$this->assertArrayNotHasKey( self::OLD_LABEL, $entries, 'the old msgid is gone' );
	}

	/** @dataProvider locales */
	public function test_the_mo_translates_the_short_label_and_drops_the_long_one( string $locale ): void {
		$entries = $this->read_mo( $this->path( $locale, 'mo' ) );
		$this->assertArrayHasKey( self::LABEL, $entries, 'the .mo is compiled from the .po' );
		$this->assertShortTranslation( $entries[ self::LABEL ], $locale );
		$this->assertArrayNotHasKey( self::OLD_LABEL, $entries, 'the old msgid is gone' );
	}

	public function test_spanish_names_the_option_as_the_old_label_did_without_its_list(): void {
		$this->assertSame( 'Almacenamiento compatible con S3', $this->read_po( $this->path( 'es_ES', 'po' ) )[ self::LABEL ] );
		$this->assertSame( 'Almacenamiento compatible con S3', $this->read_mo( $this->path( 'es_ES', 'mo' ) )[ self::LABEL ] );
	}

	private function assertShortTranslation( string $translation, string $locale ): void {
		$this->assertNotSame( '', $translation, "$locale translates it" );
		$this->assertStringContainsString( 'S3', $translation, $locale );
		$this->assertStringNotContainsString( '(', $translation, "$locale: no list in parentheses" );
	}

	private function path( string $locale, string $ext ): string {
		return dirname( __DIR__, 3 ) . "/languages/diluxone-offload-$locale.$ext";
	}

	/**
	 * Singular msgid => msgstr of a .po, continuation lines joined.
	 *
	 * @return array<string, string>
	 */
	private function read_po( string $file ): array {
		$entries = [];
		$msgid   = null;
		$msgstr  = null;
		$field   = null;
		$lines   = file( $file, FILE_IGNORE_NEW_LINES );
		$this->assertIsArray( $lines, $file );
		$lines[] = '';
		foreach ( $lines as $line ) {
			if ( preg_match( '/^(msgid|msgstr) "(.*)"$/', $line, $m ) ) {
				$field = $m[1];
				if ( 'msgid' === $field ) {
					$msgid  = stripcslashes( $m[2] );
					$msgstr = null;
				} else {
					$msgstr = stripcslashes( $m[2] );
				}
			} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
				if ( 'msgid' === $field ) {
					$msgid .= stripcslashes( $m[1] );
				} else {
					$msgstr .= stripcslashes( $m[1] );
				}
			} elseif ( '' === trim( $line ) ) {
				if ( null !== $msgid && null !== $msgstr && '' !== $msgid ) {
					$entries[ $msgid ] = $msgstr;
				}
				$msgid  = null;
				$msgstr = null;
				$field  = null;
			} else {
				// msgid_plural, msgstr[n] and comments: not a singular entry's text.
				$field = null;
			}
		}
		return $entries;
	}

	/**
	 * Original => translation of a little-endian or big-endian .mo.
	 *
	 * @return array<string, string>
	 */
	private function read_mo( string $file ): array {
		$data = file_get_contents( $file );
		$this->assertIsString( $data, $file );
		$magic = unpack( 'V', substr( $data, 0, 4 ) );
		$this->assertIsArray( $magic );
		$format = 0x950412de === $magic[1] ? 'V' : 'N';
		$header = unpack( "{$format}rev/{$format}count/{$format}originals/{$format}translations", substr( $data, 4, 16 ) );
		$this->assertIsArray( $header );
		$entries = [];
		for ( $i = 0; $i < $header['count']; $i++ ) {
			$original    = unpack( "{$format}length/{$format}offset", substr( $data, $header['originals'] + 8 * $i, 8 ) );
			$translation = unpack( "{$format}length/{$format}offset", substr( $data, $header['translations'] + 8 * $i, 8 ) );
			$this->assertIsArray( $original );
			$this->assertIsArray( $translation );
			// A plural original is "singular\0plural"; keep the singular.
			$key             = explode( "\0", substr( $data, $original['offset'], $original['length'] ) )[0];
			$entries[ $key ] = explode( "\0", substr( $data, $translation['offset'], $translation['length'] ) )[0];
		}
		return $entries;
	}
}
