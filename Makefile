# Dev workflow for shopeepay-php (Composer library).
#
# Everything runs inside a dedicated PHP container so it does not collide with
# whatever PHP is installed on the host. Swap PHP_VERSION to test against the
# CI matrix locally (8.1, 8.2, 8.3, 8.4).
#
# Usage:
#   make install                     # composer install
#   make test                        # phpunit Unit suite
#   make test-integration            # phpunit Integration suite (gated on env)
#   make phpstan                     # static analysis level 8
#   make shell                       # bash shell inside the container
#   make matrix                      # run tests across PHP 8.1-8.4
#   make clean                       # remove vendor/ and the dev image
#
# Override PHP version: make test PHP_VERSION=8.3

PHP_VERSION ?= 8.1
# Compose project names cannot contain dots — strip them when computing the slug.
PHP_SLUG     = $(subst .,,$(PHP_VERSION))
# UID is a readonly bash builtin; export DOCKER_UID/DOCKER_GID instead and
# reference them from docker-compose.dev.yml.
ENV          = PHP_VERSION=$(PHP_VERSION) DOCKER_UID=$$(id -u) DOCKER_GID=$$(id -g)
COMPOSE      = $(ENV) docker compose -f .docker/docker-compose.dev.yml -p shopeepay-dev-$(PHP_SLUG)
# RUN_BASE: `docker compose run` without the service name, so callers can
# inject `-e VAR` / `-v` flags before the service. RUN appends the service
# name (`php`) for the common case.
RUN_BASE     = $(COMPOSE) run --rm --entrypoint ""
RUN          = $(RUN_BASE) php

.PHONY: build install update test coverage test-integration phpstan shell matrix probe clean

build:
	$(COMPOSE) build

install: build
	$(RUN) composer install --prefer-dist

update: build
	$(RUN) composer update --prefer-dist

test: build
	$(RUN) vendor/bin/phpunit --testsuite Unit

coverage: build
	$(RUN) vendor/bin/phpunit --testsuite Unit --coverage-text --coverage-html coverage

test-integration: build
	$(RUN) vendor/bin/phpunit --testsuite Integration

phpstan: build
	$(RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=512M

shell: build
	$(RUN) bash

matrix:
	@for v in 8.1 8.2 8.3 8.4; do \
	  echo "===> PHP $$v" ; \
	  $(MAKE) test PHP_VERSION=$$v || exit 1 ; \
	done

# Empirical sandbox probe. Confirms /v1.0/auth/* paths, access-token TTL,
# and channelId acceptance against the live sandbox. Requires SHOPEEPAY_*
# env vars (see .env.example). Read-only — sends only probe bodies that
# the gateway rejects with validation errors.
probe: build
	$(RUN_BASE) -e SHOPEEPAY_CLIENT_ID -e SHOPEEPAY_SECRET_KEY \
	            -e SHOPEEPAY_SUBS_MERCHANT_ID -e SHOPEEPAY_SUBS_STORE_ID \
	            -e SHOPEEPAY_PRIVATE_KEY -e SHOPEEPAY_PUBLIC_KEY \
	            -e SHOPEEPAY_PRIVATE_KEY_PATH -e SHOPEEPAY_PUBLIC_KEY_PATH \
	            -e SHOPEEPAY_IS_PRODUCTION \
	            -e SHOPEEPAY_AUTH_CODE -e SHOPEEPAY_ACCOUNT_TOKEN \
	            -e SHOPEEPAY_ORIGINAL_REF \
	            php php scripts/probe-sandbox.php $(ARGS)

clean:
	rm -rf vendor composer.lock .phpunit.cache .phpunit.result.cache coverage
	-docker image rm shopeepay-dev:$(PHP_VERSION) 2>/dev/null
