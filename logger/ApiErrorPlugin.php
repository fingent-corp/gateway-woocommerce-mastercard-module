<?php
// phpcs:ignoreFile -- PSR-4 Composer autoload requires PascalCase filenames.
/**
 * HTTP client plugin that maps API error responses to exceptions.
 *
 * @package Fingent\Mastercard\Logger
 */

namespace Fingent\Mastercard\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Http\Client\Common\Plugin;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Http\Promise\Promise;

/**
 * HTTP client plugin that maps API error responses to exceptions.
 */
class ApiErrorPlugin implements Plugin {
	/**
	 * Logger variable
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * Constructor function
	 *
	 * @param LoggerInterface $logger The logger instance.
	 *
	 * @return void
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
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
		$promise = $next( $request );

		return $promise->then(
			function (
				ResponseInterface $response
			) use ( $request ) {
				return $this->transformResponseToException( $request, $response );
			}
		);
	}

	/**
	 * Transform the response to an exception.
	 *
	 * @param RequestInterface  $request The request object.
	 * @param ResponseInterface $response The response object.
	 *
	 * @return ResponseInterface
	 *
	 * @throws GatewayResponseException When the gateway returns an error response.
	 */
	protected function transformResponseToException( RequestInterface $request, ResponseInterface $response ): ResponseInterface {
		$status_code = $response->getStatusCode();

		// Handle 4xx client errors.
		if ( $status_code >= 400 && $status_code < 500 ) {
			$body = (string) $response->getBody();

			if ( '' !== $body ) {
				$response_data = json_decode( $body, true );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new GatewayResponseException( 'Response not valid JSON', 502 );
				}

				$msg = '';

				if ( isset( $response_data['error']['cause'] ) ) {
					$msg .= sanitize_text_field( (string) $response_data['error']['cause'] ) . ': ';
				}
				if ( isset( $response_data['error']['explanation'] ) ) {
					$msg .= sanitize_text_field( (string) $response_data['error']['explanation'] );
				}

				if ( '' !== $msg ) {
					$this->logger->error( 'Mastercard gateway returned a client error response.' );
					throw new GatewayResponseException( 'Payment gateway returned a client error.', (int) $status_code );
				}
			}
		}

		// Handle 5xx server errors.
		if ( $status_code >= 500 && $status_code < 600 ) {
			$this->logger->error( 'Mastercard gateway returned a server error response.' );
			throw new GatewayResponseException( 'Payment gateway returned a server error.', (int) $status_code );
		}

		return $response;
	}
}
