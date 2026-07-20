#!/usr/bin/env bash

# Dispatches an explicit semantic-version release. Routine master pushes are
# patch-released automatically after the exact revision passes CI.
set -euo pipefail

bump="${1:-patch}"
case "${bump}" in
  patch|minor|major) ;;
  *) echo "Usage: $0 [patch|minor|major]" >&2; exit 2 ;;
esac

if [[ -n "$(git status --porcelain)" ]]; then
  echo "The worktree must be clean before dispatching a release." >&2
  exit 1
fi
if [[ "$(git branch --show-current)" != "master" ]]; then
  echo "Releases must be dispatched from master." >&2
  exit 1
fi

git fetch origin master --tags
if [[ "$(git rev-parse HEAD)" != "$(git rev-parse origin/master)" ]]; then
  echo "Local master must match origin/master before release." >&2
  exit 1
fi

gh workflow run release.yml --ref master --field "bump=${bump}"
echo "Release dispatched. Follow it with: gh run watch --workflow release.yml"
