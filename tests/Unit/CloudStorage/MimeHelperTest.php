<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\MimeHelper;

/**
 * Unit tests for MimeHelper — extension → MIME type mapping plus the
 * is_stylesheet / is_script convenience predicates.
 *
 * Pure logic, no WordPress runtime needed.
 */
class MimeHelperTest extends TestCase {

    // ── get_mime_type ───────────────────────────────────────

    public function test_jpg_mime_type(): void {
        $this->assertSame('image/jpeg', MimeHelper::get_mime_type('photo.jpg'));
    }

    public function test_jpeg_mime_type(): void {
        $this->assertSame('image/jpeg', MimeHelper::get_mime_type('photo.jpeg'));
    }

    public function test_png_mime_type(): void {
        $this->assertSame('image/png', MimeHelper::get_mime_type('image.png'));
    }

    public function test_gif_mime_type(): void {
        $this->assertSame('image/gif', MimeHelper::get_mime_type('animation.gif'));
    }

    public function test_webp_mime_type(): void {
        $this->assertSame('image/webp', MimeHelper::get_mime_type('photo.webp'));
    }

    public function test_svg_mime_type(): void {
        $this->assertSame('image/svg+xml', MimeHelper::get_mime_type('icon.svg'));
    }

    public function test_css_mime_type(): void {
        $this->assertSame('text/css', MimeHelper::get_mime_type('style.css'));
    }

    public function test_js_mime_type(): void {
        $this->assertSame('application/javascript', MimeHelper::get_mime_type('app.js'));
    }

    public function test_pdf_mime_type(): void {
        $this->assertSame('application/pdf', MimeHelper::get_mime_type('doc.pdf'));
    }

    public function test_woff2_mime_type(): void {
        $this->assertSame('font/woff2', MimeHelper::get_mime_type('font.woff2'));
    }

    public function test_mp4_mime_type(): void {
        $this->assertSame('video/mp4', MimeHelper::get_mime_type('video.mp4'));
    }

    public function test_unknown_extension_returns_octet_stream(): void {
        $this->assertSame('application/octet-stream', MimeHelper::get_mime_type('file.xyz'));
    }

    public function test_path_with_directory(): void {
        $this->assertSame('image/png', MimeHelper::get_mime_type('/uploads/2026/02/photo.png'));
    }

    // ── is_stylesheet / is_script ───────────────────────────

    public function test_is_stylesheet_css(): void {
        $this->assertTrue(MimeHelper::is_stylesheet('theme.css'));
    }

    public function test_is_stylesheet_non_css(): void {
        $this->assertFalse(MimeHelper::is_stylesheet('app.js'));
    }

    public function test_is_script_js(): void {
        $this->assertTrue(MimeHelper::is_script('app.js'));
    }

    public function test_is_script_non_js(): void {
        $this->assertFalse(MimeHelper::is_script('style.css'));
    }
}
