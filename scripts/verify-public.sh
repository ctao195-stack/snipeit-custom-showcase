#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

tracked_or_present="$(find . -type f -not -path './.git/*' -print)"

if printf '%s\n' "$tracked_or_present" | grep -E '/\.env($|\.)' | grep -v '/\.env\.example$'; then
  echo 'Refusing public release: a non-example .env file exists.' >&2
  exit 1
fi

if printf '%s\n' "$tracked_or_present" | grep -E '\.(sql|sqlite|xlsx|xls|zip|7z|bak)$'; then
  echo 'Refusing public release: data, backup, or archive files exist.' >&2
  exit 1
fi

if git grep -n -I -E \
  '(snipe-it[.]xin|172[.]16[.]|/Users/ct|C:\\Users\\Administrator|hooks[.]weixin[.]qq[.]com/cgi-bin/webhook/send\?key=[A-Za-z0-9_-]+|-----BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY-----|gh[opsu]_[A-Za-z0-9]{20,})' \
  -- . ':!scripts/verify-public.sh'; then
  echo 'Refusing public release: a production identifier or credential pattern was found.' >&2
  exit 1
fi

echo 'Public repository safety checks passed.'
