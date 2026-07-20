# Repository Agent Instructions

## Documentation and code quality

- Keep code and public documentation in English.
- Preserve the Symfony-first contract: YAML creates named, non-shared, autowireable Neuron services; advanced Neuron features remain available through normal Symfony services.
- Document intent, state boundaries, security assumptions and non-obvious tradeoffs in new or significantly changed code.
- Add scenario-oriented comments to tests.
- Run `composer validate --strict` and `composer check` before pushing.

## Mandatory publication workflow

- Completed changes are published by committing them to `master` and pushing `origin master`, unless the user explicitly requests a non-publishing draft.
- Never create routine patch tags manually. A successful `CI` run for a push to `master` triggers `.github/workflows/release.yml`.
- The release workflow increments the latest semantic patch version, creates an annotated tag and GitHub Release, and asks Packagist to recrawl the package.
- Use `scripts/release.sh minor` or `scripts/release.sh major` only when an explicit non-patch version increment is required. The script requires a clean, synchronized `master` branch.
- Use `scripts/update-packagist.sh` only to repair or verify Packagist synchronization without creating a new version.
- After publishing, verify the GitHub Actions release run, the new version at `https://packagist.org/packages/errogaht/neuron-ai-bundle`, and a clean `composer require errogaht/neuron-ai-bundle:^VERSION` installation when release-related files changed.

## Release credentials

- Never commit, print, echo, or place Packagist tokens in command arguments.
- GitHub Actions reads the token from the repository secret `PACKAGIST_TOKEN`.
- Local recovery reads the encrypted, machine-bound credential from `~/.config/neuron-ai-bundle/packagist-token.cred` through `systemd-creds`.
- Do not ask the user for the token again while either configured credential source is available.
- If a credential is rotated, update both GitHub Actions Secret and the encrypted local credential, then run `scripts/update-packagist.sh`.
