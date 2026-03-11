#!/usr/bin/env bash
# ZeniClaw — Import Ollama models from mirror
# Usage: ./import-model.sh <mirror-base-url> [model-slug]
# Example: ./import-model.sh http://51.91.206.84:8080
#          ./import-model.sh http://51.91.206.84:8080 qwen2-5-3b
#
# Respects HTTP_PROXY/HTTPS_PROXY from environment.

set -euo pipefail

MIRROR_URL="${1:-}"
MODEL_SLUG="${2:-}"

if [ -z "$MIRROR_URL" ]; then
    echo "Usage: $0 <mirror-base-url> [model-slug]"
    echo "  Example: $0 http://51.91.206.84:8080"
    echo "  Example: $0 http://51.91.206.84:8080 qwen2-5-3b"
    exit 1
fi

# Strip trailing slash
MIRROR_URL="${MIRROR_URL%/}"

# Detect container runtime
CONTAINER_CMD=""
if command -v docker &>/dev/null && docker ps &>/dev/null 2>&1; then
    CONTAINER_CMD="docker"
elif command -v podman &>/dev/null; then
    CONTAINER_CMD="podman"
else
    echo "ERROR: Neither docker nor podman found."
    exit 1
fi

OLLAMA_CONTAINER="zeniclaw_ollama"

# Check Ollama container is running
if ! $CONTAINER_CMD ps --format '{{.Names}}' 2>/dev/null | grep -q "$OLLAMA_CONTAINER"; then
    # Try podman format
    if ! $CONTAINER_CMD ps --format '{{ .Names }}' 2>/dev/null | grep -q "$OLLAMA_CONTAINER"; then
        echo "ERROR: Container $OLLAMA_CONTAINER is not running."
        echo "Start it with: $CONTAINER_CMD compose --profile ollama up -d"
        exit 1
    fi
fi

# Build curl proxy args from environment
CURL_PROXY_ARGS=""
if [ -n "${HTTPS_PROXY:-}" ]; then
    CURL_PROXY_ARGS="--proxy $HTTPS_PROXY"
    echo "Using proxy: $HTTPS_PROXY"
elif [ -n "${HTTP_PROXY:-}" ]; then
    CURL_PROXY_ARGS="--proxy $HTTP_PROXY"
    echo "Using proxy: $HTTP_PROXY"
fi

if [ -n "${NO_PROXY:-}" ]; then
    CURL_PROXY_ARGS="$CURL_PROXY_ARGS --noproxy $NO_PROXY"
fi

import_model() {
    local slug="$1"
    local model_name="$2"
    local url="${MIRROR_URL}/models/download/${slug}"

    echo ""
    echo "=== Importing: $model_name ==="
    echo "    URL: $url"

    local tmpfile="/tmp/ollama-import-${slug}.tar.gz"

    # Download with proxy support
    echo "    Downloading..."
    if ! curl -fSL $CURL_PROXY_ARGS -o "$tmpfile" "$url"; then
        echo "    ERROR: Download failed for $model_name"
        rm -f "$tmpfile"
        return 1
    fi

    local size_mb
    size_mb=$(du -m "$tmpfile" | cut -f1)
    echo "    Downloaded: ${size_mb} MB"

    # Copy into container
    echo "    Copying to container..."
    $CONTAINER_CMD cp "$tmpfile" "${OLLAMA_CONTAINER}:/tmp/model-import.tar.gz"

    # Extract
    echo "    Extracting..."
    $CONTAINER_CMD exec "$OLLAMA_CONTAINER" tar xzf /tmp/model-import.tar.gz -C /root/.ollama

    # Cleanup
    $CONTAINER_CMD exec "$OLLAMA_CONTAINER" rm -f /tmp/model-import.tar.gz
    rm -f "$tmpfile"

    echo "    OK: $model_name imported successfully"
}

if [ -n "$MODEL_SLUG" ]; then
    # Import a specific model
    import_model "$MODEL_SLUG" "$MODEL_SLUG"
else
    # List available models and import all
    echo "Fetching model list from $MIRROR_URL/models/api ..."
    MODEL_LIST=$(curl -fsSL $CURL_PROXY_ARGS "${MIRROR_URL}/models/api" 2>/dev/null || echo "")

    if [ -z "$MODEL_LIST" ]; then
        echo "ERROR: Could not fetch model list from mirror."
        echo "Check the URL and try: curl ${MIRROR_URL}/models/api"
        exit 1
    fi

    # Parse JSON (basic — works with jq or python)
    if command -v jq &>/dev/null; then
        SLUGS=$(echo "$MODEL_LIST" | jq -r '.models[] | .slug')
        NAMES=$(echo "$MODEL_LIST" | jq -r '.models[] | .model')
    elif command -v python3 &>/dev/null; then
        SLUGS=$(echo "$MODEL_LIST" | python3 -c "import sys,json; [print(m['slug']) for m in json.load(sys.stdin)['models']]")
        NAMES=$(echo "$MODEL_LIST" | python3 -c "import sys,json; [print(m['model']) for m in json.load(sys.stdin)['models']]")
    else
        echo "ERROR: jq or python3 required to parse model list."
        echo "Install with: apt install -y jq"
        exit 1
    fi

    if [ -z "$SLUGS" ]; then
        echo "No models available on the mirror."
        exit 0
    fi

    # Convert to arrays
    mapfile -t SLUG_ARR <<< "$SLUGS"
    mapfile -t NAME_ARR <<< "$NAMES"

    echo "Found ${#SLUG_ARR[@]} model(s) on mirror:"
    for i in "${!NAME_ARR[@]}"; do
        echo "  - ${NAME_ARR[$i]}"
    done
    echo ""

    read -rp "Import all? [Y/n] " confirm
    if [[ "$confirm" =~ ^[Nn] ]]; then
        echo "Aborted."
        exit 0
    fi

    FAILED=0
    for i in "${!SLUG_ARR[@]}"; do
        import_model "${SLUG_ARR[$i]}" "${NAME_ARR[$i]}" || FAILED=$((FAILED + 1))
    done

    echo ""
    echo "=== Done ==="
    if [ $FAILED -gt 0 ]; then
        echo "$FAILED model(s) failed to import."
    fi
fi

echo ""
echo "Verifying installed models:"
$CONTAINER_CMD exec "$OLLAMA_CONTAINER" ollama list 2>/dev/null || echo "(ollama list not available — check with: $CONTAINER_CMD exec $OLLAMA_CONTAINER ls /root/.ollama/models/manifests/registry.ollama.ai/library/)"
