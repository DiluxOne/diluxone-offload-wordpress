<?php
/**
 * The WordPress AJAX helpers a handler ends with, for unit tests that call
 * one directly: the nonce and capability checks pass, and wp_send_json_*()
 * and wp_die() throw AjaxAnswer instead of ending the request, carrying
 * what would have been sent.
 *
 * Required only inside tests that run in their own process, so these never
 * shadow the stubs other unit tests define.
 */

namespace Tests\Unit\Support {
	final class AjaxAnswer extends \Exception {
		/** @var mixed */
		public $data;
		public bool $success;

		/** @param mixed $data */
		public function __construct( bool $success, $data ) {
			parent::__construct( 'AJAX answer sent' );
			$this->success = $success;
			$this->data    = $data;
		}
	}
}

namespace {
	if ( ! function_exists( 'check_ajax_referer' ) ) {
		function check_ajax_referer( ...$args ) {
			return 1;
		}
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( ...$args ) {
			return true;
		}
	}
	if ( ! function_exists( 'wp_send_json_error' ) ) {
		function wp_send_json_error( $data = null, $status = null, $flags = 0 ) {
			throw new \Tests\Unit\Support\AjaxAnswer( false, $data );
		}
	}
	if ( ! function_exists( 'wp_send_json_success' ) ) {
		function wp_send_json_success( $data = null, $status = null, $flags = 0 ) {
			throw new \Tests\Unit\Support\AjaxAnswer( true, $data );
		}
	}
	if ( ! function_exists( 'wp_die' ) ) {
		function wp_die( $message = '', $title = '', $args = array() ) {
			throw new \Tests\Unit\Support\AjaxAnswer( false, $message );
		}
	}
}
