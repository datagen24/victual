#!/usr/bin/env bash
# Check every rendered image before submitting any resource to the cluster.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
manifest=$(mktemp)
trap 'rm -f "$manifest"' EXIT
kubectl kustomize "$here" > "$manifest"
images=$(awk '$1 == "image:" { print $2 }' "$manifest" | sort -u)
if [ -z "$images" ]; then
  echo 'No images found in the rendered manifests; refusing to apply.' >&2
  exit 1
fi
while IFS= read -r image; do
  if ! docker manifest inspect "$image" >/dev/null; then
    echo "Image is unavailable: $image; no resources were applied." >&2
    exit 1
  fi
done <<< "$images"
kubectl apply -f "$manifest"
