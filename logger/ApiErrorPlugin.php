<?php
namespace Fingent\Mastercard\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Http\Client\Common\Exception\ClientErrorException;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Common\HttpClientRouter;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception;
use Http\Discovery\HttpClientDiscovery;
use Http\Message\Authentication\BasicAuth;
use Http\Message\Formatter;
use Http\Message\Formatter\SimpleFormatter;
use Http\Message\RequestMatcher\RequestMatcher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Http\Promise\Promise;

/**
 * The ApiErrorPlugin defines a contract for logging messages within this plugin.
 *
 * @var Mastercard_Gateway $gateway Gateway array values
 * @var WC_Abstract_Order $order Order array
 */
class ApiErrorPlugin implements Plugin {
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
	 * Constructor function
	 *
	 * @param LoggerInterface $logger The logger instance.
	 * @param Formatter       $formatter The formatter instance (optional).
	 *
	 * @return void
	 */
	public function __construct( LoggerInterface $logger, ?Formatter $formatter = null ) {
		$this->logger    = $logger;
		$this->formatter = $formatter ?? $this->getDefaultFormatter();
	}

	/**
	 * Returns the default formatter instance.
	 *
	 * This method can be overridden in subclasses to provide a custom
	 * default formatter if none is explicitly provided.
	 *
	 * @return Formatter The default formatter instance.
	 */
	protected function getDefaultFormatter(): Formatter {
	    // Can be overridden in subclasses if needed
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
	 * This function takes a request and response as input and transforms the response into an exception.
	 *
	 * @param RequestInterface  $request The request object.
	 * @param ResponseInterface $response The response object.
	 *
	 * @return array $response Response Array.
	 *
	 * @throws ServerErrorException If response is not a valid JSON.
	 * @throws ClientErrorException Throws an error with the transformed response.
	 */
	protected function transformResponseToException( RequestInterface $request, ResponseInterface $response ) {
		$status_code = $response->getStatusCode();

		// Handle 4xx client errors.
		if ( $status_code >= 400 && $status_code < 500 ) {
			$body = (string) $response->getBody();

			if ( '' !== $body ) {
				$response_data = json_decode( $body, true );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new ServerErrorException( 'Response not valid JSON', $request, $response );
				}

				$msg = '';

				if ( isset( $response_data['error']['cause'] ) ) {
					$msg .= $response_data['error']['cause'] . ': ';
				}
				if ( isset( $response_data['error']['explanation'] ) ) {
					$msg .= $response_data['error']['explanation'];
				}

				if ( '' !== $msg ) {
					$this->logger->error( $msg );
					throw new ClientErrorException( $msg, $request, $response );
				}
			}
		}

		// Handle 5xx server errors.
		if ( $status_code >= 500 && $status_code < 600 ) {
			$reason = $response->getReasonPhrase();
			$this->logger->error( $reason );
			throw new ServerErrorException( $reason, $request, $response );
		}

		return $response;
	}
}
