#!/bin/sh
# Checks that the tarball from `make package` holds the app and nothing from
# development.
#
#   scripts/check-tarball.sh [oco_synaplan.tar.gz]
set -eu

app=oco_synaplan
tarball="${1:-$app.tar.gz}"
contents="$(tar -tzf "$tarball")"

for path in appinfo/info.xml appinfo/app.php appinfo/routes.php lib/AppInfo/Application.php \
	js/files-init.js js/chat-launcher.js js/settings.js js/personal-settings.js js/research.js \
	public/link-return.html public/link-return.js templates/ img/app.svg \
	l10n/en.json l10n/en.js l10n/de.json l10n/de.js l10n/de_DE.json l10n/de_DE.js \
	l10n/es.json l10n/es.js l10n/fr.json l10n/fr.js l10n/tr.json l10n/tr.js; do
	echo "$contents" | grep -Fxq "$app/$path" || {
		echo "::error::$tarball is missing $path." >&2
		exit 1
	}
done

if echo "$contents" | grep -qE "^$app/(tests|node_modules|vendor|src|docker|scripts|docs|\.cache|\.github)/"; then
	echo "::error::$tarball contains development files." >&2
	exit 1
fi

echo "$tarball: the app and nothing from development."
