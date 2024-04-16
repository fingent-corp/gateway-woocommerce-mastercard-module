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
 * Class Mastercard_ApiLoggerPlugin
 *
 * This class implements the Plugin interface and serves as an API logger plugin for Mastercard.
 * It provides methods to log API requests and responses for debugging and analysis purposes.
 */
class Mastercard_ApiLoggerPlugin implements Plugin {
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
	 * @param Formatter|null  $formatter The formatter instance (optional).
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
		$req_body = json_decode( $request->getBody(), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$req_body = $request->getBody();
		}

		$this->logger->info(
			sprintf(
				/* translators: 1. Request */
				'Emit request: "%s"',
				$this->formatter->formatRequest( $request )
			),
			[ 'request' => $req_body ] // phpcs:ignore
		);

		return $next( $request )->then(
			function (
				ResponseInterface $response
			) use (
				$request
			) {
				$body = json_decode( $response->getBody(), true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$body = $response->getBody();
				}
				$this->logger->info(
					sprintf(
						/* translators: 1. Response, 2. Request. */
						'Receive response: "%s" for request: "%s"',
						$this->formatter->formatResponse( $response ),
						$this->formatter->formatRequest( $request )
					),
					[  // phpcs:ignore
						'response' => $body,
					]
				);

				return $response;
			},
			function ( \Exception $exception ) use ( $request ) {
				if ( $exception instanceof Exception\HttpException ) {
					$this->logger->error(
						/* translators: 1. Exception Response, 2. Request. */
						sprintf(
							'Error: "%s" with response: "%s" when emitting request: "%s"',
							$exception->getMessage(),
							$this->formatter->formatResponse( $exception->getResponse() ),
							$this->formatter->formatRequest( $request )
						),
						[ // phpcs:ignore
							'request'   => $request,
							'response'  => $exception->getResponse(),
							'exception' => $exception,
						]
					);
				} else {
					$this->logger->error(
						/* translators: 1. Request. */
						sprintf(
							'Error: "%s" when emitting request: "%s"',
							$exception->getMessage(),
							$this->formatter->formatRequest( $request )
						),
						[  // phpcs:ignore
							'request'   => $request,
							'exception' => $exception,
						]
					);
				}

				throw $exception;
			}
		);
	}
}
