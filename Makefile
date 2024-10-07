PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
	export DATABASE_URL=postgresql://aleksandr:zM9QJoPlsJ518vhhm23Se27mbmgzWsNE@dpg-crk44pd2ng1s73fonn80-a.frankfurt-postgres.render.com/websites_db_lgx3
	
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