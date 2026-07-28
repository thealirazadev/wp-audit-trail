<?php
/**
 * WP-CLI commands.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handlers behind `wp audit`.
 *
 * Output goes through injectable callables so the handlers can be exercised in tests without a
 * WP-CLI harness. Handlers return the process exit code rather than halting, and the thin closure
 * registered with WP-CLI is what turns that into a real exit status.
 */
class WPAT_Cli {

	/**
	 * Standard output writer.
	 *
	 * @var callable
	 */
	private $out;

	/**
	 * Error output writer.
	 *
	 * @var callable
	 */
	private $err;

	/**
	 * Builds a handler.
	 *
	 * @param callable|null $out Receives ( string $text, bool $newline ).
	 * @param callable|null $err Receives ( string $message ).
	 */
	public function __construct( $out = null, $err = null ) {
		$this->out = is_callable( $out ) ? $out : array( $this, 'default_out' );
		$this->err = is_callable( $err ) ? $err : array( $this, 'default_err' );
	}

	/**
	 * Registers the commands with WP-CLI.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command(
			'audit verify',
			static function ( $args, $assoc_args ) {
				$handler = new self();
				WP_CLI::halt( $handler->verify( $args, $assoc_args ) );
			},
			array(
				'shortdesc' => 'Verifies the audit chain and reports the first broken row, if any.',
				'synopsis'  => array(
					array(
						'type'     => 'assoc',
						'name'     => 'format',
						'optional' => true,
						'options'  => array( 'table', 'json' ),
						'default'  => 'table',
					),
				),
			)
		);
	}

	/**
	 * Runs a verification.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments; supports `format`.
	 * @return int Exit code: 0 intact, 1 verification failed, 2 usage error.
	 */
	public function verify( $args = array(), $assoc_args = array() ) {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			call_user_func( $this->err, 'Invalid --format value. Use table or json.' );

			return 2;
		}

		$verifier = new WPAT_Verifier();
		$header   = false;

		$progress = function ( $state ) use ( &$header, $format ) {
			if ( 'json' === $format ) {
				return;
			}

			if ( ! $header ) {
				$header = true;
				$chunks = max( 1, (int) ceil( (int) $state['total_rows'] / WPAT_Verifier::CHUNK ) );

				call_user_func(
					$this->out,
					sprintf(
						'Verifying %s rows in %d chunks...',
						number_format_i18n( (int) $state['total_rows'] ),
						$chunks
					),
					true
				);
			}

			call_user_func( $this->out, '.', false );
		};

		$result = $verifier->run( $progress );
		$report = is_array( $result['report'] ) ? $result['report'] : array();

		if ( 'json' === $format ) {
			$json = wp_json_encode( $report );
			call_user_func( $this->out, false === $json ? '{}' : $json, true );

			return $result['passed'] ? 0 : 1;
		}

		call_user_func( $this->out, '', true );

		if ( $result['passed'] ) {
			$this->print_pass( $report );

			return 0;
		}

		$this->print_failure( $report );

		return 1;
	}

	/**
	 * Prints a passing report.
	 *
	 * @param array $report Report body.
	 * @return void
	 */
	private function print_pass( array $report ) {
		call_user_func(
			$this->out,
			sprintf(
				'Chain intact. %s rows verified, %s anchors, lifetime entries %s.',
				number_format_i18n( (int) $report['verified_rows'] ),
				number_format_i18n( (int) $report['anchors_seen'] ),
				number_format_i18n( (int) $report['entry_count'] )
			),
			true
		);

		if ( '' !== (string) $report['sealed_at'] ) {
			call_user_func(
				$this->out,
				sprintf(
					'Head: %s (sealed %s UTC)',
					substr( (string) $report['head_hash'], 0, 16 ),
					(string) $report['sealed_at']
				),
				true
			);
		}
	}

	/**
	 * Prints a failing report.
	 *
	 * @param array $report Report body.
	 * @return void
	 */
	private function print_failure( array $report ) {
		call_user_func( $this->err, (string) $report['message'] );
		call_user_func( $this->out, sprintf( '  kind:     %s', (string) $report['kind'] ), true );
		call_user_func( $this->out, sprintf( '  expected: %s', (string) $report['expected'] ), true );
		call_user_func( $this->out, sprintf( '  actual:   %s', (string) $report['actual'] ), true );
	}

	/**
	 * Default standard output writer.
	 *
	 * @param string $text    Text to write.
	 * @param bool   $newline Whether to append a newline.
	 * @return void
	 */
	private function default_out( $text, $newline = true ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Terminal output, not HTML.
		echo $text . ( $newline ? "\n" : '' );
	}

	/**
	 * Default error output writer.
	 *
	 * @param string $message Message to write.
	 * @return void
	 */
	private function default_err( $message ) {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::error( $message, false );

			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Terminal output, not HTML.
		echo 'Error: ' . $message . "\n";
	}
}
