# Linux PHP runner for the Linux-only local-PTY tests (--group=linux-pty):
# the /proc/<pid>/fd/0 + stty + SIGWINCH resize path cannot run on macOS.
# 8.4 to match the workstation's composer platform (vendor/ is volume-mounted).
FROM php:8.4-cli-alpine

RUN apk add --no-cache bash procps \
    && docker-php-ext-install pcntl

WORKDIR /app
