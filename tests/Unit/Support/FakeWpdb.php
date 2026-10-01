<?php
/**
 * A scripted stand-in for WordPress's $wpdb, for the unit suite.
 *
 * It answers each call from the rules a test registers (a method and a
 * pattern matched against the query, or the table for the CRUD helpers) and
 * records every call, so a test asserts what the code under test did with
 * the rows it got back and which values it wrote, never whether a real
 * database agrees with the SQL. SQL semantics are the integration suite's.
 *
 * The file declares an empty global `wpdb` class when WordPress is not
 * loaded, because the code checks `$wpdb instanceof \wpdb` before some
 * writes, and the `ARRAY_A` constant the queries pass.
 */

namespace {
	if ( ! class_exists( 'wpdb' ) ) {
		// phpcs:ignore
		class wpdb {}
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
}

namespace Tests\Unit\Support {

	class FakeWpdb extends \wpdb {

		/** @var string */
		public $prefix = 'wp_';

		/** @var string */
		public $last_error = '';

		/** @var array<int, array{method: string, sql: string, data?: array<string, mixed>, where?: array<string, mixed>, formats?: mixed}> */
		public array $calls = array();

		/** @var array<int, array{query: string, args: array<int, mixed>}> Every prepare() with its arguments. */
		public array $prepared = array();

		/** @var array<int, array{method: string, pattern: string, value: mixed}> */
		private array $rules = array();

		/**
		 * Answer $method calls whose query (or table) matches $pattern.
		 * The first rule registered that matches wins; $value may be a
		 * Closure that receives the query (or table) and the call record.
		 *
		 * @param mixed $value
		 */
		public function on( string $method, string $pattern, $value ): self {
			$this->rules[] = array(
				'method'  => $method,
				'pattern' => $pattern,
				'value'   => $value,
			);
			return $this;
		}

		/**
		 * Answer successive matching calls with each value in turn (the last
		 * one repeats).
		 *
		 * @param array<int, mixed> $values
		 */
		public function onSequence( string $method, string $pattern, array $values ): self {
			return $this->on(
				$method,
				$pattern,
				static function () use ( &$values ) {
					return count( $values ) > 1 ? array_shift( $values ) : $values[0];
				}
			);
		}

		/** @return array<int, array<string, mixed>> The calls of one method. */
		public function callsOf( string $method ): array {
			return array_values( array_filter( $this->calls, static fn( $c ) => $c['method'] === $method ) );
		}

		/** @return array<int, string> Queries run through query(), in order. */
		public function queries(): array {
			return array_column( $this->callsOf( 'query' ), 'sql' );
		}

		/**
		 * @param array<string, mixed> $call
		 * @param mixed                $default
		 * @return mixed
		 */
		private function answer( string $method, string $subject, array $call, $default ) {
			$this->calls[] = $call;
			foreach ( $this->rules as $rule ) {
				if ( $rule['method'] === $method && preg_match( $rule['pattern'], $subject ) ) {
					return $rule['value'] instanceof \Closure ? ( $rule['value'] )( $subject, $call ) : $rule['value'];
				}
			}
			return $default;
		}

		/**
		 * Substitutes the placeholders the way WordPress does closely enough
		 * for the patterns to read the values: %s quoted, %d as an integer.
		 *
		 * @param mixed ...$args
		 */
		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$this->prepared[] = array(
				'query' => $query,
				'args'  => array_values( $args ),
			);
			$i = 0;
			return (string) preg_replace_callback(
				'/%[sdf]/',
				static function ( array $m ) use ( &$i, $args ) {
					$value = $args[ $i++ ] ?? '';
					if ( '%d' === $m[0] ) {
						return (string) (int) $value;
					}
					if ( '%f' === $m[0] ) {
						return (string) (float) $value;
					}
					return "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		/** @return mixed */
		public function get_var( string $sql ) {
			return $this->answer( 'get_var', $sql, array( 'method' => 'get_var', 'sql' => $sql ), null );
		}

		/**
		 * @param mixed $output
		 * @return mixed
		 */
		public function get_row( string $sql, $output = null ) {
			return $this->answer( 'get_row', $sql, array( 'method' => 'get_row', 'sql' => $sql ), null );
		}

		/**
		 * @param mixed $output
		 * @return mixed
		 */
		public function get_results( string $sql, $output = null ) {
			return $this->answer( 'get_results', $sql, array( 'method' => 'get_results', 'sql' => $sql ), array() );
		}

		/** @return mixed */
		public function query( string $sql ) {
			return $this->answer( 'query', $sql, array( 'method' => 'query', 'sql' => $sql ), 1 );
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $where
		 * @param mixed                $formats
		 * @param mixed                $where_formats
		 * @return mixed
		 */
		public function update( string $table, array $data, array $where, $formats = null, $where_formats = null ) {
			return $this->answer( 'update', $table, array( 'method' => 'update', 'sql' => $table, 'data' => $data, 'where' => $where, 'formats' => $formats ), 1 );
		}

		/**
		 * @param array<string, mixed> $data
		 * @param mixed                $formats
		 * @return mixed
		 */
		public function insert( string $table, array $data, $formats = null ) {
			return $this->answer( 'insert', $table, array( 'method' => 'insert', 'sql' => $table, 'data' => $data, 'formats' => $formats ), 1 );
		}

		/**
		 * @param array<string, mixed> $data
		 * @param mixed                $formats
		 * @return mixed
		 */
		public function replace( string $table, array $data, $formats = null ) {
			return $this->answer( 'replace', $table, array( 'method' => 'replace', 'sql' => $table, 'data' => $data, 'formats' => $formats ), 1 );
		}

		/**
		 * @param array<string, mixed> $where
		 * @param mixed                $formats
		 * @return mixed
		 */
		public function delete( string $table, array $where, $formats = null ) {
			return $this->answer( 'delete', $table, array( 'method' => 'delete', 'sql' => $table, 'where' => $where, 'formats' => $formats ), 1 );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}
	}
}
