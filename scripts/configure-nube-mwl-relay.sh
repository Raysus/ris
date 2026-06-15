#!/usr/bin/env bash
set -euo pipefail
ENV_FILE="/var/www/ris.healthticloud.cl/backend/.env"
RELAY_LINE='LAB_MWL_RELAY_URLS={"ef633609-3144-17e1-7086-3eb7c8baed74":"http://100.103.135.42/api/integrations/local-mwl/relay"}'

if grep -q '^LAB_MWL_RELAY_URLS=' "$ENV_FILE" 2>/dev/null; then
  sed -i "s|^LAB_MWL_RELAY_URLS=.*|$RELAY_LINE|" "$ENV_FILE"
else
  echo "$RELAY_LINE" >> "$ENV_FILE"
fi

grep LAB_MWL_RELAY_URLS "$ENV_FILE"
