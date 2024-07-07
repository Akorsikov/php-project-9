PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

setup:
	composer install

compose:
	docker-compose up

compose-bash:
	docker-compose run web bash

compose-setup: compose-build
	docker-compose run web make setup

compose-build:
	docker-compose build

compose-down:
	docker-compose down -v

valid:
	composer validate	

dump:
	composer dump-autoload

lint:
	composer exec --verbose phpcs -- --standard=PSR12 --colors public

lint-fix:
	composer exec --verbose phpcbf -- --standard=PSR12 --colors public

analyse:
	vendor/bin/phpstan analyse --level 9 public
