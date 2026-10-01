<?php
/**
 * The wp-admin functions the Admin class calls and the shared stubs do not
 * carry, for the Admin unit tests only.
 *
 * Each one answers like WordPress unless a test hands it another answer in
 * $GLOBALS['_test_admin_fn'][ name ] (a callable taking the same arguments).
 * AdminTestCase clears those overrides around every test.
 *
 * Not Brain Monkey: it loads Patchwork, whose file:// stream wrapper changes
 * how the providers' part streams read, and the rest of the suite then fails
 * depending on test order.
 */

if ( ! function_exists( '_test_admin_fn' ) ) {
	/**
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	function _test_admin_fn( string $name, array $args, callable $default ) {
		$override = $GLOBALS['_test_admin_fn'][ $name ] ?? null;
		return is_callable( $override ) ? $override( ...$args ) : $default( ...$args );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) {
		return _test_admin_fn(
			__FUNCTION__,
			$args,
			static function ( array $query, string $url ): string {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
			}
		);
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return _test_admin_fn( __FUNCTION__, array(), static fn() => 7 );
	}
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return _test_admin_fn( __FUNCTION__, array(), static fn() => null );
	}
}
if ( ! function_exists( 'get_user_option' ) ) {
	function get_user_option( ...$args ) {
		return _test_admin_fn( __FUNCTION__, $args, static fn() => false );
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() {
		return _test_admin_fn( __FUNCTION__, array(), static fn() => 'production' );
	}
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( ...$args ) {
		return _test_admin_fn( __FUNCTION__, $args, static fn() => array( 'Version' => '' ) );
	}
}
if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( ...$args ) {
		return _test_admin_fn( __FUNCTION__, $args, static fn() => array() );
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( ...$args ) {
		return _test_admin_fn( __FUNCTION__, $args, static fn() => false );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( ...$args ) {
		return _test_admin_fn( __FUNCTION__, $args, static fn() => false );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return implode( "\n", array_map( static fn( $line ) => trim( strip_tags( $line ) ), explode( "\n", (string) $str ) ) );
	}
}
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( ...$args ) {
		$GLOBALS['_test_admin_calls']['add_menu_page'][] = $args;
		return _test_admin_fn( __FUNCTION__, $args, static fn() => 'toplevel_page_' . $args[3] );
	}
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( ...$args ) {
		$GLOBALS['_test_admin_calls']['add_submenu_page'][] = $args;
		return _test_admin_fn( __FUNCTION__, $args, static fn() => 'submenu' );
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( ...$args ) {
		$GLOBALS['_test_admin_calls']['wp_safe_redirect'][] = $args;
		return true;
	}
}
