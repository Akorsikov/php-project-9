PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

localhost:
	php -S localhost:8080 -t public public/index.php

setup:
	composer install

update:
	composer update

valid:
	composer validate	

lint:
	composer exec --verbose phpcs -- --standard=PSR12 --colors public src

lint-fix:
	composer exec --verbose phpcbf -- --standard=PSR12 --colors public src

analyse:
	vendor/bin/phpstan analyse --level 9 public src