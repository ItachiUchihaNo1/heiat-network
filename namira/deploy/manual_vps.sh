#!/usr/bin/env bash
# Local upload alternative: reuse the root-owned deployment gateway without SSH.
set -euo pipefail
check_only=false
if [[ "${1:-}" == '--check' ]]; then check_only=true; shift; fi
if [[ $# != 3 || ! "$2" =~ ^[a-f0-9]{40}$ || ! "$3" =~ ^[a-f0-9]{64}$ ]]; then
  echo 'Usage: bash manual_vps.sh [--check] payload.tar.gz revision sha256' >&2
  exit 2
fi
payload="$1"; revision="$2"; digest="$3"
if [[ ! -f "$payload" || -L "$payload" ]]; then
  echo 'A regular local release archive is required.' >&2; exit 1
fi
actual="$(sha256sum -- "$payload")"; actual="${actual%% *}"
if [[ "$actual" != "$digest" ]]; then
  echo 'Release checksum mismatch; installation stopped.' >&2; exit 1
fi
if "$check_only"; then
  echo 'Release checksum and arguments verified; no server change performed.'
  exit 0
fi
if [[ "$EUID" != 0 ]]; then
  echo 'Run the installer with sudo.' >&2; exit 1
fi
helper='/usr/local/lib/namira-deploy/vps_gateway.py'
if [[ ! -f "$helper" || -L "$helper" || "$(stat -c %u "$helper")" != 0 ]]; then
  echo 'Previously configured root-owned VPS deployment helper is required.' >&2
  exit 1
fi
/usr/sbin/runuser -u namira-deploy -- /usr/bin/env -i \
  PATH=/usr/bin:/bin LANG=C.UTF-8 \
  "SSH_ORIGINAL_COMMAND=deploy $revision $digest" \
  /usr/bin/python3 "$helper" < "$payload"
