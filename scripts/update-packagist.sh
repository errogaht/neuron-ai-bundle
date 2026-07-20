#!/usr/bin/env bash

# Forces Packagist to recrawl the package. The token comes from an environment
# variable or a machine-bound systemd credential and is never stored in Git.
set -euo pipefail

credential_file="${XDG_CONFIG_HOME:-${HOME}/.config}/neuron-ai-bundle/packagist-token.cred"
token="${PACKAGIST_TOKEN:-}"

if [[ -z "${token}" && -f "${credential_file}" ]] && command -v systemd-creds >/dev/null 2>&1; then
  token="$(systemd-creds --user --name=packagist-token decrypt "${credential_file}" -)"
fi
if [[ -z "${token}" ]]; then
  echo "Packagist token is unavailable in PACKAGIST_TOKEN or ${credential_file}." >&2
  exit 1
fi

curl --fail-with-body --silent --show-error \
  --request POST \
  --header 'Content-Type: application/json' \
  --header "Authorization: Bearer errogaht:${token}" \
  --data '{"repository":"https://github.com/errogaht/neuron-ai-bundle"}' \
  https://packagist.org/api/update-package
printf '\n'
unset token
