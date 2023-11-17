BASE_COMMAND=docker-compose -p $(shell basename $(CURDIR))

build:
	$(BASE_COMMAND) build --pull

test: build
	$(BASE_COMMAND) run test
	$(BASE_COMMAND) down

mtest:
	$(BASE_COMMAND) run test

update:
	$(BASE_COMMAND) run test composer update
	$(BASE_COMMAND) down

bash:
	$(BASE_COMMAND) run test /bin/sh

down:
	$(BASE_COMMAND) down --remove-orphans
