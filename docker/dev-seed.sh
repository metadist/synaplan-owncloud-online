#!/bin/sh
# Runs on every `make dev-up`, as www-data inside the ownCloud.online container
# once Apache answers. Safe to repeat: brings the app's tables and background
# jobs up to the checkout (dev-migrate.sh) and gives Ada the folders and
# shares the cloud journeys use (the same as the Nextcloud dev stack).
set -eu

ADA=ada:ada-dev-pass
DAV=http://localhost/remote.php/dav/files/ada
OCS=http://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares

if ! curl -fs -o /dev/null -u "$ADA" -X PROPFIND -H 'Depth: 0' "$DAV/"; then
	echo "ada cannot sign in: this ownCloud.online was installed before the dev setup." >&2
	echo "'make dev-reset dev-up' deletes the local ownCloud.online data and installs it." >&2
	exit 1
fi

sh "$(dirname "$0")/dev-migrate.sh"

for folder in Specs Incoming-DE Incoming-EN Reports Invoices Exports Projects Projects/Q3 HR HR/Contracts; do
	code=$(curl -s -o /dev/null -w '%{http_code}' -u "$ADA" -X MKCOL "$DAV/$folder")
	case "$code" in
		201 | 405) ;;
		*) echo "Creating $folder for ada answered HTTP $code" >&2; exit 1 ;;
	esac
done

share() { # path, user, permissions
	if curl -s -u "$ADA" -H 'OCS-APIRequest: true' "$OCS?path=/$1&format=json" | grep -q "\"share_with\":\"$2\""; then
		return
	fi
	curl -fsS -o /dev/null -u "$ADA" -H 'OCS-APIRequest: true' -X POST "$OCS" \
		-d "path=/$1" -d shareType=0 -d "shareWith=$2" -d "permissions=$3"
}

share Incoming-DE bob 15 # read, update, create, delete
share Specs bob 1        # read

echo "Seeded: ada's folders; Incoming-DE (edit) and Specs (read) shared with bob"
