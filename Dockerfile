# The posteria.app site and its APIs, served by PHP's built-in web server.
#
# This replaces the nixpacks build. nixpacks took PHP from a pinned package
# snapshot, which left the live site on PHP 8.3.15 long after it was current and
# exposed it to PHP2Shell (CVE-2026-109618 / CVE-2026-109619). The official image
# is updated within a day or so of each PHP release.
#
# `8.5-cli` is a moving tag: every fresh build gets the newest 8.5 patch release.
# Nothing updates a running container on its own, though. A redeploy in Coolify is
# what picks up a new release, and .github/workflows/php-currency.yml checks weekly
# that one has happened.
FROM php:8.5-cli

COPY docker/php.ini "$PHP_INI_DIR/conf.d/posteria.ini"

WORKDIR /app
COPY . .

# Not root: the server only needs to read the site and write its cache under /tmp.
USER www-data

# Coolify's "Ports Exposes" setting must match this.
ENV PORT=8080
EXPOSE 8080

# router.php keeps the repository's internal files off the site, exactly as it
# did under nixpacks.
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT} -t /app /app/router.php"]
