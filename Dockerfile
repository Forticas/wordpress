FROM wordpress:latest

COPY php/custom.ini $PHP_INI_DIR/conf.d/