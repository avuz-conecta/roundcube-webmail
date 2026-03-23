#!/bin/bash
set -e

REGISTRY="10.50.100.103:8080"
ORG="admin"
BASE_IMAGE_NAME="avuz-roundcube-base"
VERSION=${1:-latest}
ENV=${2:-local}

case $ENV in
  local)
    PLATFORM="linux/arm64"
    PUSH=false
    IMAGE_TAG="${BASE_IMAGE_NAME}:${VERSION}"
    IMAGE_TAG_LATEST="${BASE_IMAGE_NAME}:latest"
    ;;
  staging)
    PLATFORM="linux/amd64"
    PUSH=true
    IMAGE_TAG="${REGISTRY}/${ORG}/${BASE_IMAGE_NAME}:staging"
    IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${BASE_IMAGE_NAME}:staging"
    ;;
  prod)
    PLATFORM="linux/amd64"
    PUSH=true
    IMAGE_TAG="${REGISTRY}/${ORG}/${BASE_IMAGE_NAME}:${VERSION}"
    IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${BASE_IMAGE_NAME}:latest"
    ;;
  *)
    echo "Usage: $0 [version] [local|staging|prod]"
    exit 1
    ;;
esac

echo "==========================================="
echo "Building BASE image"
echo "  Image:    ${IMAGE_TAG}"
echo "  Platform: ${PLATFORM}"
echo "  Push:     ${PUSH}"
echo "==========================================="

docker buildx build \
  --platform ${PLATFORM} \
  -f Dockerfile.base \
  -t ${IMAGE_TAG} \
  -t ${IMAGE_TAG_LATEST} \
  --load \
  .

echo "✓ Build completed: ${IMAGE_TAG_LATEST}"

if [ "$PUSH" = true ]; then
  docker push ${IMAGE_TAG}
  docker push ${IMAGE_TAG_LATEST}
  echo "✓ Pushed ${IMAGE_TAG_LATEST}"
fi

echo ""
echo "Now run: ./scripts/build-push.sh [version] [local|staging|prod]"
