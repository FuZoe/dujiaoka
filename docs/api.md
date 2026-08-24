# Shop API v1

This API lets an owner integration list products, create orders, open the existing payment checkout, query an order, read delivery results, and submit a verified settlement that runs the normal delivery pipeline.

Base URL: `https://shop.newzoe.cloud/api/v1`

The API is owner-scoped. Configure a dedicated key and secret in the shop environment before use:

```dotenv
DUJIAOKA_API_KEY=YOUR_API_KEY
DUJIAOKA_API_SECRET=YOUR_API_SECRET
DUJIAOKA_API_TIMESTAMP_TOLERANCE=300
DUJIAOKA_API_IDEMPOTENCY_TTL=86400
```

Generate both values with a cryptographically secure random generator. Keep them outside source control and rotate them together when an integration is replaced.

## Authentication

Every v1 request uses these headers:

| Header | Description |
| --- | --- |
| `X-Api-Key` | The configured API key. |
| `X-Api-Timestamp` | Unix timestamp in seconds. The default acceptance window is 5 minutes. |
| `X-Api-Nonce` | A unique 8-128 character value for this request. |
| `X-Api-Signature` | Lowercase hexadecimal HMAC-SHA256 signature. |
| `Content-Type` | `application/json` for requests with a body. |

Sign this exact five-line string with `DUJIAOKA_API_SECRET`:

```text
HTTP_METHOD\n
/api/v1/path[?query]\n
X-Api-Timestamp\n
X-Api-Nonce\n
RAW_REQUEST_BODY
```

The path includes `/api/v1`, the method is uppercase, and the body is the exact byte sequence sent on the wire. GET requests use an empty final line. A nonce is accepted once only.

Example signing helper in Python:

```python
import hashlib
import hmac
import secrets
import time

def api_headers(method, path, body, api_key, api_secret):
    timestamp = str(int(time.time()))
    nonce = secrets.token_urlsafe(18)
    canonical = "\n".join([method.upper(), path, timestamp, nonce, body])
    signature = hmac.new(
        api_secret.encode(), canonical.encode(), hashlib.sha256
    ).hexdigest()
    return {
        "X-Api-Key": api_key,
        "X-Api-Timestamp": timestamp,
        "X-Api-Nonce": nonce,
        "X-Api-Signature": signature,
        "Content-Type": "application/json",
    }
```

All responses use this envelope:

```json
{ "ok": true, "data": {} }
```

Errors use `ok: false` and a stable `error.code`:

```json
{ "ok": false, "error": { "code": "order_not_found", "message": "The order does not exist." } }
```

## Products

### `GET /products`

Returns currently listed products and payment methods. Card contents and provider credentials are never included.

```json
{
  "ok": true,
  "data": {
    "products": [
      {
        "id": 12,
        "name": "Example product",
        "description": "Short description",
        "price": "9.90",
        "stock": 42,
        "type": "automatic",
        "max_quantity": 5,
        "wholesale_prices": [],
        "input_fields": []
      }
    ],
    "payment_methods": [
      { "id": 30, "code": "binancepay", "name": "币安支付", "method": 2, "client": 3 }
    ]
  }
}
```

### `GET /payment-methods`

Returns the same payment method list without product data. Scheduled payment pauses are applied.

## Orders

### `POST /orders`

Creates one order through the same pricing, coupon, stock, and expiry path as the web checkout. `Idempotency-Key` is required and must be reused when retrying a timed-out request.

Request fields:

| Field | Required | Description |
| --- | --- | --- |
| `product_id` | yes | Product id from `/products`. `gid` is accepted as a legacy alias. |
| `quantity` | yes | Positive integer. `by_amount` is accepted as a legacy alias. |
| `email` | yes | Delivery and order notification address. |
| `payment_method` | yes | Payment `code` or numeric `id`. |
| `search_password` | depends on shop setting | Password used by storefront order lookup. |
| `coupon_code` | no | Coupon assigned to this product. |
| `inputs` | no | Object containing manual-processing fields listed by the product. |

Example:

```http
POST /api/v1/orders
Idempotency-Key: order-cart-20260824-0001
```

```json
{
  "product_id": 12,
  "quantity": 1,
  "email": "customer@example.com",
  "payment_method": "binancepay",
  "search_password": "ORDER_PASSWORD",
  "inputs": {}
}
```

Response status is `201` for a new order and `200` when the same idempotency key replays the original result. The response includes `data.payment.url`, which is the existing checkout page:

```json
{
  "ok": true,
  "data": {
    "replayed": false,
    "order": {
      "id": "ORDER_SN",
      "status": "wait_pay",
      "status_code": 1,
      "payment_received": false,
      "fulfilled": false,
      "quantity": 1,
      "amount": "9.90",
      "currency": "CNY",
      "expires_at": "2026-08-24T12:25:00+08:00"
    },
    "payment": {
      "required": true,
      "url": "https://shop.newzoe.cloud/pay-gateway/...",
      "method": "binancepay",
      "expires_at": "2026-08-24T12:25:00+08:00"
    }
  }
}
```

### `POST /orders/{order_id}/pay`

Returns a payment URL for an existing unpaid order. The optional JSON field `payment_method` can select another currently available channel. This endpoint only starts checkout; it does not mark an order paid.

```json
{ "payment_method": "binancepay" }
```

When `payment_method` is `binancepay`, this response also creates or reuses the
active collision-free quote and includes the verified receiving deep link for
QR rendering:

```json
{
  "payment_required": true,
  "payment": {
    "method": "binancepay",
    "url": "https://shop.newzoe.cloud/pay-gateway/...",
    "qr_payload": "https://app.binance.com/uni-qr/RECEIVE_CODE",
    "expected_usdt": "1.38",
    "currency": "USDT",
    "quote_expires_at": "2026-08-24T12:15:00+08:00"
  }
}
```

The Telegram bot renders `qr_payload` into an image and sends it in the chat;
it does not add a URL button for Binance. WeChat and Alipay continue to use the
normal `payment.url` button. The `POST /orders` response stays URL-only until
the integration explicitly opens `/pay`, so an unused order does not consume a
Binance quote window.

Payment callbacks from the configured provider call the same settlement service used by the browser checkout. A paid order returns `409 order_already_paid` from this endpoint.

### `GET /orders/{order_id}`

Returns status, amount, payment method, transaction id, timestamps, and the live payment deadline. Card contents are excluded; use `/delivery` after payment.

Status values are `wait_pay`, `pending`, `processing`, `completed`, `failure`, `expired`, and `abnormal`.

### `GET /orders/{order_id}/delivery`

Returns delivery state. For an automatic order, `items` and `content` are present only after the order reaches `completed`. Manual orders stay `pending` until the back office finishes them.

```json
{
  "ok": true,
  "data": {
    "delivery": {
      "available": true,
      "status": "completed",
      "type": "automatic",
      "items": ["CARD-CONTENT"],
      "content": "CARD-CONTENT"
    }
  }
}
```

### `POST /orders/{order_id}/deliver`

For a trusted payment integration, submits the provider's verified settlement and triggers the normal stock claim, automatic delivery, email, Telegram, and product-hook jobs. The exact order amount and a non-empty provider transaction id are required.

```json
{
  "amount": "9.90",
  "transaction_id": "PROVIDER_TRANSACTION_ID"
}
```

`amount_fen` can be used instead of `amount` for integer cents:

```json
{ "amount_fen": 990, "transaction_id": "PROVIDER_TRANSACTION_ID" }
```

This endpoint is intended for a server-side payment verifier. A client should use `/pay` and wait for the provider callback. Repeating the same transaction id and amount is idempotent; a different transaction id or amount is rejected. An expired unpaid order is not settled by this endpoint.

## Error codes

| HTTP | Code | Meaning |
| --- | --- | --- |
| 401 | `unauthorized` | API key is invalid. |
| 401 | `invalid_timestamp` | Request timestamp is outside the configured window. |
| 401 | `invalid_signature` | HMAC does not match the raw request. |
| 401 | `request_replayed` | Nonce has already been consumed. |
| 409 | `idempotency_conflict` | Same idempotency key was used with another body. |
| 409 | `payment_method_unavailable` | Channel is closed or paused. |
| 409 | `order_already_paid` | The order has left `wait_pay`. |
| 410 | `order_expired` | Payment or settlement window has ended. |
| 422 | `validation_error` | Request fields failed validation. |
| 422 | `amount_mismatch` | Settlement amount differs from the order. |
| 404 | `order_not_found` | No order matches the supplied id. |

## Recommended flow

1. Call `GET /products` and choose a product and payment code.
2. Call `POST /orders` with a fresh `Idempotency-Key` and save `order.id`.
3. Redirect the customer to `data.payment.url`.
4. Poll `GET /orders/{order_id}` or wait for the configured payment callback.
5. When `status` is `completed`, call `GET /orders/{order_id}/delivery` and read the card items.
6. For a separate payment provider, verify its server callback and call `/deliver` exactly once with the exact amount and transaction id.
