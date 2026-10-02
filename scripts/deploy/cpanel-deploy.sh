#!/usr/bin/env bash
set -euo pipefail

APP_PATH="/home/horizon1/repositories/AfiliadoSystem"

echo "========================================"
echo " Deploy Vértice / AfiliadoSystem"
echo "========================================"

cd "$APP_PATH"

echo "[deploy] Aplicação: $APP_PATH"
echo "[deploy] Commit: $(git rev-parse --short HEAD)"

# Diretório persistente - NÃO deve ser apagado pelo deploy
mkdir -p "$APP_PATH/.runtime/app-data"

# Permissões dos diretórios públicos
chmod 755 "$APP_PATH"
chmod 755 "$APP_PATH/public"

# Arquivos públicos
find "$APP_PATH/public" -type d -exec chmod 755 {} \;
find "$APP_PATH/public" -type f -exec chmod 644 {} \;

# Runtime precisa ser gravável pelo PHP
chmod 755 "$APP_PATH/.runtime"
chmod 755 "$APP_PATH/.runtime/app-data"

echo "[deploy] Deploy concluído."

