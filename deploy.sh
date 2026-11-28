#!/bin/bash
set -euo pipefail

# ========================
# 配置（所有文件都在 AOSSTP8 目录）
# ========================
APP_ROOT="/www/wwwroot/AOSSTP8"
ARCHIVE_PATH="$APP_ROOT/AOSSTP8.tar.gz"
DOCKER_COMPOSE_PATH="$APP_ROOT/docker-compose.yml"

TOKEN_FILE="$APP_ROOT/.github_token"
REPO="tobycroft/AOSSTP8"

# ========================
# 前置检查
# ========================
echo "========== $(date) =========="

if [ ! -f "$TOKEN_FILE" ]; then
    echo "GitHub token file not found: $TOKEN_FILE"
    exit 1
fi
TOKEN=$(cat "$TOKEN_FILE")

mkdir -p "$APP_ROOT"
cd "$APP_ROOT"

# ========================
# 获取最新 release
# ========================
echo "Fetching latest release..."
RELEASE_JSON=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/vnd.github+json" \
  "https://api.github.com/repos/$REPO/releases/latest")

TAG=$(echo "$RELEASE_JSON" | jq -r '.tag_name')
ARCHIVE_ASSET_ID=$(echo "$RELEASE_JSON" | jq -r '.assets[] | select(.name=="AOSSTP8.tar.gz") | .id')
COMPOSE_ASSET_ID=$(echo "$RELEASE_JSON" | jq -r '.assets[] | select(.name=="docker-compose.yml") | .id')

if [ -z "$TAG" ] || [ "$TAG" = "null" ]; then
    echo "❌ Tag not found in release"
    exit 1
fi

echo "Latest release: $TAG"

# ========================
# 停止容器
# ========================
echo "Stopping container..."
docker compose -f "$DOCKER_COMPOSE_PATH" down -v || true

# ========================
# 下载代码包
# ========================
echo "Downloading archive..."
curl -L -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/octet-stream" \
  "https://api.github.com/repos/$REPO/releases/assets/$ARCHIVE_ASSET_ID" \
  -o "$ARCHIVE_PATH"

# ========================
# 解压代码（.env、.github_token 不会被覆盖）
# ========================
echo "Extracting archive..."
tar -xzf "$ARCHIVE_PATH" -C "$APP_ROOT"
rm -f "$ARCHIVE_PATH"

# ========================
# 下载 docker-compose.yml
# ========================
echo "Downloading docker-compose.yml from release..."
curl -L -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/octet-stream" \
  "https://api.github.com/repos/$REPO/releases/assets/$COMPOSE_ASSET_ID" \
  -o "$DOCKER_COMPOSE_PATH"

# ========================
# 目录权限（runtime、上传目录需可写）
# ========================
mkdir -p "$APP_ROOT/runtime" "$APP_ROOT/public/upload"
chown -R 1000:1000 "$APP_ROOT/runtime" "$APP_ROOT/public/upload"

# ========================
# 启动容器
# ========================
echo "Starting container..."
docker compose -f "$DOCKER_COMPOSE_PATH" up -d --force-recreate

echo "✅ 部署成功！版本：$TAG"
