#!/bin/bash
set -e

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
APP_ID="signotecsignosignuniversal"
DEFAULT_CONTAINER="master-nextcloud-1"
NC_APP_PATH="/var/www/html/apps/$APP_ID"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"

CERT_DIR="/mnt/u/Zertifikate/Nextcloud"
KEY_FILE="$CERT_DIR/$APP_ID.key"
CRT_FILE="$CERT_DIR/$APP_ID.crt"
SIGNATURE_OUT="$REPO_DIR/appinfo/signature.json"

CONTAINER_TMP="/tmp/$APP_ID-signing"
# ---------------------------------------------------------------------------

cleanup() {
  if [[ -n "$CONTAINER" ]]; then
    docker exec "$CONTAINER" rm -rf "$CONTAINER_TMP" 2>/dev/null || true
  fi
}
trap cleanup EXIT

# Auto-mount U: if not mounted
if [[ ! -d "/mnt/u" ]] || ! mountpoint -q /mnt/u; then
  echo "Mounting U: drive..."
  sudo mkdir -p /mnt/u
  sudo mount -t drvfs U: /mnt/u
fi

# Check key + cert exist
if [[ ! -f "$KEY_FILE" ]]; then
  echo "ERROR: Private key not found at $KEY_FILE"
  exit 1
fi
if [[ ! -f "$CRT_FILE" ]]; then
  echo "ERROR: Certificate not found at $CRT_FILE"
  exit 1
fi

# Select container
echo "Running containers:"
docker ps --format "  {{.Names}}\t{{.Status}}"
echo ""
read -rp "Container [$DEFAULT_CONTAINER]: " CONTAINER
CONTAINER="${CONTAINER:-$DEFAULT_CONTAINER}"

echo ""
echo "Using container: $CONTAINER"
echo "App path in container: $NC_APP_PATH"
echo ""

# Copy key + cert into container
docker exec "$CONTAINER" mkdir -p "$CONTAINER_TMP"
docker cp "$KEY_FILE" "$CONTAINER:$CONTAINER_TMP/$APP_ID.key"
docker cp "$CRT_FILE" "$CONTAINER:$CONTAINER_TMP/$APP_ID.crt"

# Ensure appinfo is writable by www-data
docker exec "$CONTAINER" chown www-data:www-data "$NC_APP_PATH/appinfo"

# Run occ integrity:sign-app
echo "Signing app..."
docker exec -u www-data "$CONTAINER" php occ integrity:sign-app \
  --privateKey="$CONTAINER_TMP/$APP_ID.key" \
  --certificate="$CONTAINER_TMP/$APP_ID.crt" \
  --path="$NC_APP_PATH"

# Copy signature.json back to repo
echo "Copying signature.json..."
docker cp "$CONTAINER:$NC_APP_PATH/appinfo/signature.json" "$SIGNATURE_OUT"

echo ""
echo "Done: $SIGNATURE_OUT"
