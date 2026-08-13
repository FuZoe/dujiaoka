# NewZoe Pay

一分钱微信收款页和实时支付状态服务。

## 支付通知

`POST /api/payment/notify` 接收 JSON：

```json
{
  "amountFen": 1,
  "transactionId": "provider-transaction-id",
  "paidAt": "2026-08-11T08:00:00.000Z"
}
```

请求头：

- `X-Pay-Timestamp`: Unix 秒时间戳，允许前后五分钟。
- `X-Pay-Signature`: `HMAC-SHA256(PAY_NOTIFY_SECRET, timestamp + "." + rawBody)` 的十六进制结果。

测试指定浏览器时可增加 `clientId` 和 `"mode": "test"`。测试事件只发送给该浏览器，且不写入交易记录。

## SmsForwarder 通知入口

`POST /api/smsf/notify` 接收 SmsForwarder Webhook 默认表单参数：

- `from`: APP 包名，微信必须是 `com.tencent.mm`。
- `content`: 通知模板处理后的内容。
- `timestamp`: Unix 毫秒时间戳，允许前后五分钟。
- `sign`: SmsForwarder 生成的 Base64 HMAC-SHA256 签名。

服务端使用 `SMSF_NOTIFY_SECRET` 或 `SMSF_NOTIFY_SECRET_FILE` 验签。只有微信通知同时包含可信来源、收款关键词和待支付订单金额时才会确认支付。

- 同一商户、同一金额有多笔订单时，按收银台激活时间从早到晚匹配。
- 默认接受最近 24 小时内激活的订单，可通过 `SMSF_ORDER_ACTIVE_HOURS` 调整。
- 已识别为到账但没有对应订单时返回 HTTP 409，SmsForwarder 不应将其显示为成功。
- 最近 500 条验签通过的转发结果写入状态文件，并可在支付后台“设置”页审计。

## 运行

```powershell
$env:PAY_NOTIFY_SECRET = "至少 32 个字符的随机值"
npm start
```

```powershell
npm test
```
