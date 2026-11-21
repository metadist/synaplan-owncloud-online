#!/usr/bin/env bash
# The OCP interfaces of the ownCloud.online release the app is checked against.
#
# ownCloud.online publishes no OCP package, so PHPUnit and PHPStan read
# lib/public of the BW-Tech checkout at OCO_TAG: a sparse clone of that tag in
# .cache/owncloud.online-<tag>, linked as .cache/ocp (composer.json autoloads
# OCP\ from there). Nothing here is committed.
#
#   scripts/ocp.sh                  # v11.0.10, the version the README names
#   OCO_TAG=v11.0.29 scripts/ocp.sh # the newest v11.0.*
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
tag="${OCO_TAG:-v11.0.10}"
repo="${OCO_REPO:-https://github.com/BWTECH-github/owncloud.online.git}"
checkout="$root/.cache/owncloud.online-$tag"

if [ ! -f "$checkout/lib/public/IConfig.php" ]; then
	rm -rf "$checkout"
	mkdir -p "$root/.cache"
	git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$tag" \
		--filter=blob:none --sparse "$repo" "$checkout"
	git -C "$checkout" sparse-checkout set lib/public
fi

ln -sfn "owncloud.online-$tag/lib/public" "$root/.cache/ocp"
echo "OCP from ownCloud.online $tag ($(git -C "$checkout" rev-parse --short HEAD))"
