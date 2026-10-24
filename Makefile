IMAGE := specrig-php
PROTOCOL ?= $(abspath $(CURDIR)/../protocol)

# The checkout at its own path, as the samples' Makefiles mount theirs; the
# resources start beside it, through the host's Docker.
RUN := docker run --rm -v $(CURDIR):$(CURDIR) -v $(IMAGE)-composer:/tmp/composer -e COMPOSER_HOME=/tmp/composer \
	-v /var/run/docker.sock:/var/run/docker.sock -e TESTCONTAINERS_HOST_OVERRIDE=host.docker.internal \
	--add-host=host.docker.internal:host-gateway -w $(CURDIR) $(IMAGE)

install:
	composer install -q
	composer install -q -d samples/shop
	composer install -q -d samples/reminders

generate: install
	cd samples/shop && php vendor/bin/specrig surface && php vendor/bin/specrig agents
	cd samples/reminders && php vendor/bin/specrig surface && php vendor/bin/specrig agents

test: install
	vendor/bin/phpunit
	samples/shop/vendor/bin/phpunit -c packages/laravel/phpunit.xml
	samples/reminders/vendor/bin/phpunit -c packages/symfony/phpunit.xml
	cd samples/shop && vendor/bin/behat -c tests/behat.php
	cd samples/reminders && vendor/bin/behat -c tests/behat.php

image:
	docker build -q -t $(IMAGE) samples/shop/tests/internal > /dev/null

install-docker: image
	$(RUN) sh -c 'composer install -q && composer install -q -d samples/shop && composer install -q -d samples/reminders'

generate-docker: install-docker
	$(RUN) sh -c 'cd samples/shop && php vendor/bin/specrig surface && php vendor/bin/specrig agents && cd ../reminders && php vendor/bin/specrig surface && php vendor/bin/specrig agents'

test-docker: install-docker
	$(RUN) sh -c 'vendor/bin/phpunit \
		&& samples/shop/vendor/bin/phpunit -c packages/laravel/phpunit.xml \
		&& samples/reminders/vendor/bin/phpunit -c packages/symfony/phpunit.xml \
		&& (cd samples/shop && vendor/bin/behat -c tests/behat.php) \
		&& (cd samples/reminders && vendor/bin/behat -c tests/behat.php)'

protocol:
	rm -rf packages/specrig/protocol tests/fixtures/protocol
	mkdir -p packages/specrig/protocol/agents tests/fixtures/protocol/records tests/fixtures/protocol/agents tests/fixtures/protocol/markers
	cp $(PROTOCOL)/modes.yaml packages/specrig/protocol/
	cp $(PROTOCOL)/agents/*.md packages/specrig/protocol/agents/
	cp $(PROTOCOL)/fixtures/records/* tests/fixtures/protocol/records/
	cp $(PROTOCOL)/fixtures/agents/*.yaml tests/fixtures/protocol/agents/
	cp $(PROTOCOL)/fixtures/markers/*.yaml tests/fixtures/protocol/markers/

.PHONY: install generate test image install-docker generate-docker test-docker protocol
