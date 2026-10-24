SPECRIG ?= $(abspath $(CURDIR)/../..)
ROOT := $(abspath $(CURDIR)/..)
IMAGE := specrig-$(notdir $(ROOT))-$(notdir $(CURDIR))

# The repository at its own path, so a contract or a package outside the service
# resolves as it does on the host; the resources start beside it.
RUN := docker run --rm -v $(ROOT):$(ROOT) -v $(SPECRIG):$(SPECRIG) -v $(IMAGE)-composer:/tmp/composer -e COMPOSER_HOME=/tmp/composer \
	-v /var/run/docker.sock:/var/run/docker.sock -e TESTCONTAINERS_HOST_OVERRIDE=host.docker.internal \
	--add-host=host.docker.internal:host-gateway -w $(CURDIR) $(IMAGE)

generate:
	php vendor/bin/specrig surface
	php vendor/bin/specrig agents

test:
	php vendor/bin/behat -c tests/behat.php

image:
	docker build -q -t $(IMAGE) tests/internal > /dev/null

generate-docker: image
	$(RUN) sh -c 'composer install --no-interaction --quiet && php vendor/bin/specrig surface && php vendor/bin/specrig agents'

test-docker: image
	$(RUN) sh -c 'composer install --no-interaction --quiet && php vendor/bin/behat -c tests/behat.php'

.PHONY: generate test image generate-docker test-docker
