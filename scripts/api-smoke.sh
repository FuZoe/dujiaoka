#!/usr/bin/env sh
set -eu

BASE_URL="${BASE_URL:-https://shop.newzoe.cloud}"
CREDENTIALS_FILE="${CREDENTIALS_FILE:-/home/ubuntu/dujiaoka-api-credentials.txt}"
API_KEY="$(sed -n 's/^DUJIAOKA_API_KEY=//p' "$CREDENTIALS_FILE")"
API_SECRET="$(sed -n 's/^DUJIAOKA_API_SECRET=//p' "$CREDENTIALS_FILE")"
METHOD=GET
PATH_INFO=/api/v1/products
TIMESTAMP="$(date +%s)"
NONCE="api-smoke-$(date +%s%N)"
SIGNATURE="$(printf '%s\n%s\n%s\n%s\n' "$METHOD" "$PATH_INFO" "$TIMESTAMP" "$NONCE" | openssl dgst -sha256 -hmac "$API_SECRET" | awk '{print $NF}')"

curl -fsS "$BASE_URL$PATH_INFO" \
  -H "X-Api-Key: $API_KEY" \
  -H "X-Api-Timestamp: $TIMESTAMP" \
  -H "X-Api-Nonce: $NONCE" \
  -H "X-Api-Signature: $SIGNATURE"
printf '\n'
