<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package  Mastercard
 * @version  GIT: @1.4.4@
 * @link     https://github.com/fingent-corp/gateway-woocommerce-mastercard-module/
 */

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
 * The Mastercard_ApiErrorPlugin defines a contract for logging messages within this plugin.
 *
 * @var Mastercard_Gateway $gateway Gateway array values
 * @var WC_Abstract_Order $order Order array
 */
class Mastercard_ApiErrorPlugin implements Plugin {
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
	public function __construct( LoggerInterface $logger, Formatter $formatter = null ) {
		$this->logger    = $logger;
		$this->formatter = $formatter ? $formatter : new SimpleFormatter();
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
		if ( $response->getStatusCode() >= 400 && $response->getStatusCode() < 500 ) {
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new ServerErrorException( 'Response not valid JSON', esc_attr( $request ), esc_attr( $response ) );
			}
			if ( $response->getBody()->getContents() ) {
				$response_data = json_decode( $response->getBody(), true );
				$msg           = '';

				if ( isset( $response_data['error']['cause'] ) ) {
					$msg .= $response_data['error']['cause'] . ': ';
				}
				if ( isset( $response_data['error']['explanation'] ) ) {
					$msg .= $response_data['error']['explanation'];
				}
				$this->logger->error( $msg );
				throw new ClientErrorException( esc_attr( $msg ), esc_attr( $request ), esc_attr( $response ) );
			}
		}

		if ( $response->getStatusCode() >= 500 && $response->getStatusCode() < 600 ) {
			$this->logger->error( $response->getReasonPhrase() );
			throw new ServerErrorException( esc_attr( $response->getReasonPhrase() ), esc_attr( $request ), esc_attr( $response ) );
		}

		return $response;
	}
}
