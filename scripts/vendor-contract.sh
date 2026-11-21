#!/bin/sh
# Vendors the cloud-apps contract fixtures of the pinned Synaplan into
# tests/fixtures/synaplan-contract/, byte for byte, and writes their checksums
# to tests/fixtures/synaplan-contract.sha256. The source is the commit the
# pinned image was built from (its org.opencontainers.image.revision label),
# read from a metadist/synaplan checkout. Never edit the vendored files.
#
#   scripts/vendor-contract.sh                       # from ../synaplan
#   SYNAPLAN_DIR=/path/to/synaplan scripts/vendor-contract.sh
#   scripts/vendor-contract.sh --check               # the fixtures are the pinned Synaplan's
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
digest="${SYNAPLAN_IMAGE_DIGEST:-$(sed -n 's/^ *SYNAPLAN_IMAGE_DIGEST: *\(sha256:[0-9a-f]*\).*/\1/p' "$root/.github/workflows/ci.yml")}"
if [ -z "$digest" ]; then
	echo "No SYNAPLAN_IMAGE_DIGEST in .github/workflows/ci.yml." >&2
	exit 1
fi
image="ghcr.io/metadist/synaplan@$digest"
source_dir=backend/tests/Fixtures/cloud-apps-contract
target="$root/tests/fixtures/synaplan-contract"
manifest="$root/tests/fixtures/synaplan-contract.sha256"

docker image inspect "$image" >/dev/null 2>&1 || docker pull -q "$image" >/dev/null
rev="$(docker image inspect "$image" --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}')"
if ! echo "$rev" | grep -qE '^[0-9a-f]{40}$'; then
	echo "The pinned Synaplan image has no source revision label." >&2
	exit 1
fi

if [ "${1:-}" = --check ]; then
	vendored="$(sed -n 's/^# metadist\/synaplan \([0-9a-f]\{40\}\) .*/\1/p' "$manifest")"
	if [ "$vendored" != "$rev" ]; then
		echo "::error::tests/fixtures/synaplan-contract comes from ${vendored:-nowhere}; the pinned Synaplan is $rev. Run scripts/vendor-contract.sh." >&2
		exit 1
	fi
	(cd "$root/tests/fixtures" && grep -v '^#' synaplan-contract.sha256 | sha256sum -c --quiet -)
	echo "tests/fixtures/synaplan-contract: the fixtures of the pinned Synaplan ($rev)."
	exit 0
fi

src="${SYNAPLAN_DIR:-$root/../synaplan}"
if [ ! -d "$src/.git" ]; then
	echo "No metadist/synaplan checkout at $src: set SYNAPLAN_DIR." >&2
	exit 1
fi
git -C "$src" cat-file -e "$rev^{commit}" 2>/dev/null || git -C "$src" fetch -q origin "$rev"

rm -rf "$target"
mkdir -p "$target"
for file in $(git -C "$src" ls-tree --name-only "$rev" "$source_dir/"); do
	git -C "$src" show "$rev:$file" >"$target/${file#"$source_dir"/}"
done
{
	echo "# metadist/synaplan $rev $source_dir"
	cd "$root/tests/fixtures" && sha256sum synaplan-contract/*
} >"$manifest"

echo "Vendored $source_dir at $rev:"
cat "$manifest"
