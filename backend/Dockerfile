FROM php:8.3-cli
WORKDIR /app
COPY . /app
ENV PORT=10000
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
