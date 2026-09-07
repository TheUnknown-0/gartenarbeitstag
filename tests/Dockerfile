# Schlankes Test-Image: php:8.3-cli + pdo_mysql, damit PHPUnit gegen die
# Compose-Datenbank laufen kann. Bauen: docker build -t gartenarbeitstag-test tests
FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql
