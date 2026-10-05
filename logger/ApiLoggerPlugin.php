<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * API request/response logger for the Mastercard HTTP client.
 *
 * @package Fingent\Mastercard\Logger
 */

namespace Fingent\Mastercard\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Http\Client\Common\Plugin;
use Http\Client\Exception;
use Http\Message\Formatter;
use Http\Message\Formatter\SimpleFormatter;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Http\Promise\Promise;
use Fingent\Mastercard\Helper\LogRedactor;

/**
 * Logs Mastercard API requests and responses for debugging.
 */
class ApiLoggerPlugin implements Plugin {
	/**
	 * Logger variable
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * Formatter variable
	 *
	 * @var Formatter
	 */
	private $formatter;

	/**
	 * Whether to log request/response bodies (debug only).
	 *
	 * @var bool
	 */
	private $log_bodies;

	/**
	 * Constructor function
	 *
	 * @param LoggerInterface $logger The logger instance.
	 * @param Formatter|null  $formatter The formatter instance (optional).
	 * @param bool            $log_bodies Log HTTP bodies when true.
	 *
	 * @return void
	 */
	public function __construct( LoggerInterface $logger, ?Formatter $formatter = null, $log_bodies = false ) {
		$this->logger     = $logger;
		$this->formatter  = $formatter ?? $this->getDefaultFormatter();
		$this->log_bodies = (bool) $log_bodies;
	}

	/**
	 * Returns the default formatter instance.
	 *
	 * @return Formatter The default formatter instance.
	 */
	protected function getDefaultFormatter(): Formatter {
		// Can be overridden in subclasses if needed.
		return new SimpleFormatter();
	}

	/**
	 * Handle a request using the provided middleware functions.
	 *
	 * @param RequestInterface $request The request to be handled.
	 * @param callable         $next The next middleware function to be called.
	 * @param callable         $first The first middleware function to be called.
	 *
	 * @return Promise A promise that resolves with the response.
	 */
	public function handleRequest( RequestInterface $request, callable $next, callable $first ): Promise {
		$req_body = json_decode( (string) $request->getBody(), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$req_body = $request->getBody();
		}

		$context = array();

		if ( $this->log_bodies && is_array( $req_body ) ) {
			$context['request'] = LogRedactor::redact( $req_body );
		}

		$this->logger->info(
			sprintf(
				/* translators: 1. Request */
				'Emit request: "%s"',
				$this->formatter->formatRequest( $request )
			),
			$context
		);

		return $next( $request )->then(
			function ( ResponseInterface $response ) use ( $request ) {
				$body = json_decode( (string) $response->getBody(), true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$body = $response->getBody();
				}
				$response_context = array();

				if ( $this->log_bodies && is_array( $body ) ) {
					$response_context['response'] = LogRedactor::redact( $body );
				}

				$this->logger->info(
					sprintf(
						/* translators: 1. Response, 2. Request. */
						'Receive response: "%s" for request: "%s"',
						$this->formatter->formatResponse( $response ),
						$this->formatter->formatRequest( $request )
					),
					$response_context
				);

				return $response;
			},
			function ( \Exception $exception ) use ( $request ) {
				if ( $exception instanceof Exception\HttpException ) {
					$error_context = array(
						'exception' => get_class( $exception ),
					);

					if ( $exception->getCode() ) {
						$error_context['code'] = $exception->getCode();
					}

					$this->logger->error(
						sprintf(
							/* translators: 1. Exception Response, 2. Request. */
							'Error: "%s" with response: "%s" when emitting request: "%s"',
							sanitize_text_field( $exception->getMessage() ),
							$this->formatter->formatResponse( $exception->getResponse() ),
							$this->formatter->formatRequest( $request )
						),
						$error_context
					);
				} else {
					$this->logger->error(
						sprintf(
							/* translators: 1. Request. */
							'Error: "%s" when emitting request: "%s"',
							sanitize_text_field( $exception->getMessage() ),
							$this->formatter->formatRequest( $request )
						),
						array(
							'exception' => get_class( $exception ),
							'code'      => (int) $exception->getCode(),
						)
					);
				}

				throw $exception;
			}
		);
	}
}
