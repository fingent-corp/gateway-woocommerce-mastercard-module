<?php
/**
 * WooCommerce template for 3DS authentication redirect.
 *
 * @package Fingent\Mastercard
 *
 * @var WC_Abstract_Order $order Order instance.
 * @var array             $args  Template arguments.
 */

$authentication_redirect = $args['authenticationRedirect'];
$return_url              = $args['returnUrl'];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
	<head>
		<title><?php esc_html_e( 'Processing Secure Payment', 'mastercard-gateway' ); ?></title>
		<meta http-equiv="content-type" content="text/html; charset=utf-8"/>
		<meta name="description" content="<?php esc_attr_e( 'Processing Secure Payment', 'mastercard-gateway' ); ?>"/>
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
<?php if ( ! isset( $authentication_redirect['acsUrl'], $authentication_redirect['paReq'] ) ) : ?>
	<body>
		<p><?php esc_html_e( 'Data Error', 'mastercard-gateway' ); ?></p>
	</body>
<?php else : ?>
	<body onload="return window.document.echoForm.submit()">
		<form name="echoForm" method="post" action="<?php echo esc_url( $authentication_redirect['acsUrl'] ); ?>" accept-charset="UTF-8" id="echoForm">
			<input type="hidden" name="PaReq" value="<?php echo esc_attr( $authentication_redirect['paReq'] ); ?>" />
			<input type="hidden" name="TermUrl" value="<?php echo esc_url( $return_url ); ?>" />
			<input type="hidden" name="MD" value=""/>
			<noscript>
				<div id="msg">
					<div id="submitButton">
						<input type="submit" value="<?php esc_attr_e( 'Click here to continue', 'mastercard-gateway' ); ?>" class="button" />
					</div>
				</div>
			</noscript>
		</form>
	</body>
<?php endif; ?>
</html>
