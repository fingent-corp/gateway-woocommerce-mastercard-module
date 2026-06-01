<?php
/**
 * WooCommerce template for 3DS
 *
 * @var WC_Abstract_Order
 * @var array $args
 */
$authenticationRedirect = $args[ 'authenticationRedirect' ];
$returnUrl              = $args[ 'returnUrl' ];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
	<head>
		<title><?php esc_html_e( 'Processing Secure Payment', MG_ENTERPRISE_TEXTDOMAIN ); ?></title>
		<meta http-equiv="content-type" content="text/html; charset=utf-8"/>
		<meta name="description" content="<?php esc_html_e( 'Processing Secure Payment', MG_ENTERPRISE_TEXTDOMAIN ); ?>"/>
		<meta name="robots" content="noindex"/>
		<style type="text/css">
			body {
				font-family: "Trebuchet MS", sans-serif;
				background-color: #FFFFFF;
			}

			#msg {
				border: 5px solid #666;
				background-color: #fff;
				margin: 20px;
				padding: 25px;
				max-width: 40em;
				-webkit-border-radius: 10px;
				-khtml-border-radius: 10px;
				-moz-border-radius: 10px;
				border-radius: 10px;
			}

			#submitButton {
				text-align: center;
			}

			#footnote {
				font-size: 0.8em;
			}
		</style>
	</head>
<?php if ( ! isset( $authenticationRedirect['acsUrl'], $authenticationRedirect['paReq'] ) ) : // phpcs:ignore ?>
	<body>
		<p><?php esc_html_e( 'Data Error', MG_ENTERPRISE_TEXTDOMAIN ); ?></p>
	</body>
<?php else : ?>
	<body onload="return window.document.echoForm.submit()">
		<form name="echoForm" method="post" action="<?php echo esc_url( $authenticationRedirect['acsUrl'] ); // phpcs:ignore ?>" accept-charset="UTF-8" id="echoForm">
			<input type="hidden" name="PaReq" value="<?php echo esc_attr( $authenticationRedirect['paReq'] ); // phpcs:ignore ?>" />
			<input type="hidden" name="TermUrl" value="<?php echo esc_url( $returnUrl ); // phpcs:ignore ?>" />
			<input type="hidden" name="MD" value=""/>
			<noscript>
				<div id="msg">
					<div id="submitButton">
						<input type="submit" value="<?php esc_html_e( 'Click here to continue', MG_ENTERPRISE_TEXTDOMAIN ); ?>" class="button" />
					</div>
				</div>
			</noscript>
		</form>
	</body>
<?php endif; ?>
</html>