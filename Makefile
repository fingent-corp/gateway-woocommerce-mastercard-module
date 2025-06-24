all: check-composer
	php composer.phar install --no-ansi --no-dev --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader && \
	git archive HEAD -o ./woocommerce-mastercard.zip && \
	zip -rq ./woocommerce-mastercard.zip ./vendor && \
	echo "\nCreated woocommerce-mastercard.zip\n"

check-composer:
	@test -f composer.phar || (echo "composer.phar not found. Run 'make download-composer' first."; exit 1)

download-composer:
	curl -sS https://getcomposer.org/installer | php
