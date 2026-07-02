#!/bin/bash
set -e

REGISTRY="registry.avuz.app"
ORG="admin"
IMAGE_NAME="avuz-roundcube"
BASE_IMAGE_NAME="avuz-roundcube-base"
BROKER_IMAGE_NAME="avuz-password-broker"
SIDECAR_IMAGE_NAME="avuz-imapproxy-sidecar"
VERSION=${1:-latest}
ENV=${2:-local}

case $ENV in
  local)
    PLATFORM="linux/arm64"
    PUSH=false
    IMAGE_TAG="${IMAGE_NAME}:${VERSION}"
    IMAGE_TAG_LATEST="${IMAGE_NAME}:latest"
    BASE_IMAGE="${BASE_IMAGE_NAME}:latest"
    BROKER_IMAGE_TAG="${BROKER_IMAGE_NAME}:${VERSION}"
    BROKER_IMAGE_TAG_LATEST="${BROKER_IMAGE_NAME}:latest"
    SIDECAR_IMAGE_TAG="${SIDECAR_IMAGE_NAME}:${VERSION}"
    SIDECAR_IMAGE_TAG_LATEST="${SIDECAR_IMAGE_NAME}:latest"
    ;;
  staging)
    PLATFORM="linux/amd64"
    PUSH=true
    IMAGE_TAG="${REGISTRY}/${ORG}/${IMAGE_NAME}:staging"
    IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${IMAGE_NAME}:staging"
    BASE_IMAGE="${REGISTRY}/${ORG}/${BASE_IMAGE_NAME}:staging"
    BROKER_IMAGE_TAG="${REGISTRY}/${ORG}/${BROKER_IMAGE_NAME}:staging"
    BROKER_IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${BROKER_IMAGE_NAME}:staging"
    SIDECAR_IMAGE_TAG="${REGISTRY}/${ORG}/${SIDECAR_IMAGE_NAME}:staging"
    SIDECAR_IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${SIDECAR_IMAGE_NAME}:staging"
    ;;
  prod)
    PLATFORM="linux/amd64"
    PUSH=true
    IMAGE_TAG="${REGISTRY}/${ORG}/${IMAGE_NAME}:${VERSION}"
    IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${IMAGE_NAME}:latest"
    BASE_IMAGE="${REGISTRY}/${ORG}/${BASE_IMAGE_NAME}:latest"
    BROKER_IMAGE_TAG="${REGISTRY}/${ORG}/${BROKER_IMAGE_NAME}:${VERSION}"
    BROKER_IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${BROKER_IMAGE_NAME}:latest"
    SIDECAR_IMAGE_TAG="${REGISTRY}/${ORG}/${SIDECAR_IMAGE_NAME}:${VERSION}"
    SIDECAR_IMAGE_TAG_LATEST="${REGISTRY}/${ORG}/${SIDECAR_IMAGE_NAME}:latest"
    ;;
  *)
    echo "Usage: $0 [version] [local|staging|prod]"
    exit 1
    ;;
esac

echo "==========================================="
echo "Building APP image"
echo "  Image:    ${IMAGE_TAG}"
echo "  Base:     ${BASE_IMAGE}"
echo "  Platform: ${PLATFORM}"
echo "  Push:     ${PUSH}"
echo "==========================================="

docker buildx build \
  --platform ${PLATFORM} \
  --build-arg BASE_IMAGE=${BASE_IMAGE} \
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

echo "==========================================="
echo "Building BROKER image"
echo "  Image:    ${BROKER_IMAGE_TAG}"
echo "  Platform: ${PLATFORM}"
echo "  Push:     ${PUSH}"
echo "==========================================="

docker buildx build \
  --platform ${PLATFORM} \
  -t ${BROKER_IMAGE_TAG} \
  -t ${BROKER_IMAGE_TAG_LATEST} \
  --load \
  services/password-broker

echo "✓ Build completed: ${BROKER_IMAGE_TAG_LATEST}"

if [ "$PUSH" = true ]; then
  docker push ${BROKER_IMAGE_TAG}
  docker push ${BROKER_IMAGE_TAG_LATEST}
  echo "✓ Pushed ${BROKER_IMAGE_TAG_LATEST}"
fi

echo "==========================================="
echo "Building IMAPPROXY SIDECAR image"
echo "  Image:    ${SIDECAR_IMAGE_TAG}"
echo "  Platform: ${PLATFORM}"
echo "  Push:     ${PUSH}"
echo "==========================================="

docker buildx build \
  --platform ${PLATFORM} \
  -t ${SIDECAR_IMAGE_TAG} \
  -t ${SIDECAR_IMAGE_TAG_LATEST} \
  --load \
  docker/imapproxy-sidecar

echo "✓ Build completed: ${SIDECAR_IMAGE_TAG_LATEST}"

if [ "$PUSH" = true ]; then
  docker push ${SIDECAR_IMAGE_TAG}
  docker push ${SIDECAR_IMAGE_TAG_LATEST}
  echo "✓ Pushed ${SIDECAR_IMAGE_TAG_LATEST}"
fi
