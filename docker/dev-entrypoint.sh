#!/bin/sh
# Starts the ownCloud.online dev container. Role "apache" (default) installs
# the server's PHP dependencies, installs ownCloud.online once, enables the app
# and runs Apache; role "cron" waits for the install and runs the background
# jobs every 60 seconds. Safe to repeat: a second start skips the install.
set -eu

ROOT=/var/www/html
DATA=/var/www/owncloud-data
APP=oco_synaplan
role=${1:-apache}

if [ ! -f "$ROOT/version.php" ]; then
	echo "No ownCloud.online checkout at OCO_SERVER_PATH: run 'make dev-server' first." >&2
	exit 1
fi

# Apache and occ run as www-data with the UID of the checkout's owner, so the
# checkout stays editable on the host.
uid=$(stat -c %u "$ROOT/version.php")
gid=$(stat -c %g "$ROOT/version.php")
if [ "$uid" != 0 ] && [ "$(id -u www-data)" != "$uid" ]; then
	groupmod -o -g "$gid" www-data
	usermod -o -u "$uid" -g "$gid" www-data
fi
mkdir -p "$DATA"
chown www-data:www-data "$ROOT/config" "$DATA"

as_www() { runuser -u www-data -- env HOME=/tmp COMPOSER_HOME=/tmp/composer "$@"; }
occ() { as_www php "$ROOT/occ" "$@"; }
installed() { [ -f "$ROOT/config/config.php" ] && grep -q "'installed' => true" "$ROOT/config/config.php"; }

version=$(sed -n "s/^\$OC_VersionString = '\(.*\)';/\1/p" "$ROOT/version.php")
if [ -n "${OCO_TAG:-}" ] && [ "v$version" != "$OCO_TAG" ]; then
	echo "The checkout at OCO_SERVER_PATH is ownCloud.online $version, not $OCO_TAG." >&2
	echo "Check out the tag there ('make dev-server'), then 'make dev-reset dev-up'." >&2
	exit 1
fi

if [ "$role" = cron ]; then
	until installed; do sleep 5; done
	echo "Running background jobs every 60 seconds (ownCloud.online $version)"
	while true; do
		occ system:cron || echo "system:cron failed" >&2
		sleep 60
	done
fi

# A release tarball ships lib/composer; a git checkout does not. The v11.0.10
# lock pins checksums of codeberg.org archives that codeberg no longer serves
# byte for byte (later tags dropped those archives), so those come from git.
as_www composer config --global 'preferred-install.icewind/*' source
as_www composer install --working-dir="$ROOT" --no-dev --no-interaction --no-progress

until mysqladmin ping --skip-ssl -h db -u owncloud -p"$DB_PASS" --silent; do sleep 2; done

if ! installed; then
	occ maintenance:install --database mysql --database-host db --database-name owncloud \
		--database-user owncloud --database-pass "$DB_PASS" \
		--admin-user admin --admin-pass admin --data-dir "$DATA"
	occ config:system:set trusted_domains 1 --value owncloud
	occ config:system:set trusted_domains 2 --value host.docker.internal
	occ config:system:set overwrite.cli.url --value http://localhost:8083
	occ config:system:set skeletondirectory --value ''
	occ config:system:set default_language --value en
	occ background:cron

	# The test people of the cloud journeys. Carol has her own address on
	# ownCloud.online: Synaplan does not provision a second account for an
	# email that already exists, and the Nextcloud stack's Carol has one.
	for person in ada:Ada:ada@example.org bob:Bob:bob@example.org carol:Carol:carol.oco@example.org; do
		uid=${person%%:*}
		rest=${person#*:}
		as_www env OC_PASS="${uid}-dev-pass" php "$ROOT/occ" user:add --password-from-env \
			--display-name="${rest%%:*}" --email="${rest#*:}" "$uid"
	done

	# The app reaches Synaplan from inside Docker; the defaults below are a
	# Synaplan API published on the Docker host.
	occ app:enable "$APP"
	occ config:app:set "$APP" active_env --value local
	occ config:app:set "$APP" synaplan_url_local --value "${SYNAPLAN_URL_LOCAL:-http://host.docker.internal:8000}"
	# The address people's browsers open Synaplan at, when it is not the one above.
	if [ -n "${SYNAPLAN_PUBLIC_URL_LOCAL:-}" ]; then
		occ config:app:set "$APP" synaplan_public_url_local --value "$SYNAPLAN_PUBLIC_URL_LOCAL"
	fi
fi

occ app:enable "$APP"

echo "ownCloud.online $version is installed; starting Apache"
exec apache2-foreground
