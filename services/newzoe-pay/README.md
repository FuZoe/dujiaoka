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

- 同一商户存在相同商品金额的有效订单时，新订单会自动向上顺延 0.01 元，确保每笔待支付订单的实际收款金额唯一。
- 卡网商品原价保存在 `baseAmountFen`，收银台展示和到账匹配使用唯一的 `amountFen`；回调卡网时仍使用商品原价完成严格金额校验。
- 卡网收银台默认提供 20 分钟支付窗口，到期后页面隐藏二维码且刷新不会续期。
- 支付窗口结束后页面进入“到账确认中”，继续保留 5 分钟到账宽限；SmsForwarder 可以处理已经在途的通知，宽限内到账仍会显示支付成功，满 25 分钟后停止匹配。
- 待支付和已支付订单的实际收款金额都占用到该订单的到账宽限截止时间，随后金额可重新使用。
- 金额池按收款商户隔离；同一商户的卡网订单和手工收款单共用金额池，不同商户可以使用相同金额。
- 手工收款单可在创建时指定 1 至 1440 分钟的支付有效期，同样自动增加 5 分钟到账宽限。
- 默认支付窗口可通过 `PAY_ORDER_TTL_MINUTES` 调整，到账宽限可通过 `PAY_SETTLEMENT_GRACE_MINUTES` 调整。
- 无订单通知的重放审计窗口默认保持 24 小时，可通过 `SMSF_REPLAY_HOURS` 调整。
- 已识别为到账但没有对应订单时返回 HTTP 409，SmsForwarder 不应将其显示为成功。
- 最近 500 条验签通过的转发结果写入状态文件，并可在支付后台“设置”页审计。

## 人工确认支付

登录 `/admin` 后，可以在待支付订单上选择“标记已支付”。卡网订单必须同时选择处理方式：

- 勾选“继续触发卡网自动发货”：更新支付状态后调用 `shop.newzoe.cloud` 的签名回调。
- 取消勾选：记录为“人工已发货”，永久抑制该订单的卡网回调。

已经成功回调或标记为人工发货的订单不会重复触发发货；回调失败的订单可在后台明确选择“重试发货”。手工收款单只更新支付状态，不涉及卡网回调。

## 运行

```powershell
$env:PAY_NOTIFY_SECRET = "至少 32 个字符的随机值"
npm start
```

```powershell
npm test
```
