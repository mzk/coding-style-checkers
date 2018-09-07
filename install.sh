#!/usr/bin/env bash

composer install  --no-interaction --prefer-dist --no-dev -d code-checker;
composer install  --no-interaction --prefer-dist --no-dev -d php-parallel-lint;
composer install  --no-interaction --prefer-dist --no-dev -d phpcs;
composer install  --no-interaction --prefer-dist --no-dev -d phpstan;
