# Wiring Pinakes releases to this Docker image

Add this to **Pinakes' `scripts/create-release.sh`**, right after the release is
published (after the `[9.6/9] Publishing the verified draft release` step). It
fires a `repository_dispatch` so `pinakes-docker` rebuilds and republishes the
image for the new version automatically.

```bash
# --- [10/9] Trigger the Docker image rebuild (non-fatal) -------------------
# Notifies fabiodalez-dev/pinakes-docker that a new release shipped, so its
# build-publish workflow rebuilds the multi-arch image for ${VERSION}.
if command -v gh >/dev/null 2>&1; then
    echo -e "${YELLOW}[10/9] Notifying pinakes-docker to rebuild the image…${NC}"
    if gh api -X POST "repos/fabiodalez-dev/pinakes-docker/dispatches" \
        -f "event_type=pinakes_release" \
        -F "client_payload[pinakes_version]=${VERSION}" >/dev/null 2>&1; then
        echo -e "${GREEN}✓ Docker image rebuild triggered for v${VERSION}${NC}"
    else
        echo -e "${YELLOW}⚠ Could not trigger pinakes-docker (non-fatal).${NC}"
        echo "  Trigger manually: https://github.com/fabiodalez-dev/pinakes-docker/actions/workflows/build-publish-docker.yml"
    fi
fi
```

## Requirements

- The `gh` CLI used to cut the release must be authenticated with a token that
  has **`repo`** scope on `fabiodalez-dev/pinakes-docker` (the default
  `gh auth login` token for the repo owner already does).
- The Docker repo's `build-publish-docker.yml` listens for
  `repository_dispatch: types: [pinakes_release]` and reads
  `client_payload.pinakes_version`.

## If the dispatch is ever missed

The Docker repo also runs a **daily cron poller** (`auto-update-on-release.yml`)
that compares the upstream "latest release" to `.latest-pinakes-version` and
triggers a build if they differ — so the image still catches up within 24h even
if the dispatch above fails or isn't installed.

You can also trigger a build by hand:

```bash
gh workflow run build-publish-docker.yml -R fabiodalez-dev/pinakes-docker -f version=0.7.23
```
