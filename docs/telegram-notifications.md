# Telegram 通知与顾客绑定

## 通知隔离

- `Telegram补货目标` 只接收商品补货公告，不接收订单号、邮箱、卡密或其他顾客数据。
- 顾客订单只发送到顾客主动绑定的机器人私聊。群组、超级群组和频道不能完成绑定。
- `补货通知` 与 `顾客订单私聊通知` 是两个独立开关。`私聊通知发送卡密` 默认开启，可单独关闭。

## 频道设置

1. 在 Telegram 打开目标频道的管理员设置，把机器人添加为管理员。
2. 至少授予机器人“发布消息”权限。
3. 公开频道在后台 `系统设置 -> 订单推送配置 -> Telegram补货目标` 填写 `@channel_username`，也可以直接粘贴 `t.me/channel_username` 或 `https://t.me/channel_username`。
4. 私有频道填写字符串形式的数值 chat_id，通常以 `-100` 开头，例如 `-1001234567890`。机器人必须是频道管理员并拥有发布权限。
5. 如果暂时没有频道，也可以填写管理员私聊的正数 chat_id；补货公告会发到该私聊。这个值不能接收频道广播。
6. 获取私有频道 ID 时，可先让机器人收到频道消息，再调用 Bot API `getUpdates`，查找 `channel_post.chat.id`；也可把频道消息转发给可显示原始 chat_id 的工具机器人。
7. 用 `php artisan telegram:restock-test` 发送测试公告。文案固定包含“补货通知测试”。

Bot API 请求使用 `sendMessage` 的 POST JSON；频道用户名与负数 chat_id 都作为 JSON 字符串发送。

## Webhook 与绑定

1. 在生产环境设置 `TELEGRAM_WEBHOOK_SECRET`，使用随机生成的高强度值；不要复用 Bot Token。
2. 运行 `php artisan telegram:webhook-set`。命令从后台缓存读取现有 Bot Token，调用 `getMe` 与 `setWebhook`，并提示应写入 `TELEGRAM_BOT_USERNAME` 的机器人用户名。
3. 把用户名写入生产环境并重建应用容器。Bot Token 仍只保存在后台设置中。
4. 健康检查：`GET https://shop.newzoe.cloud/api/telegram/webhook/health`。响应只显示用户名与 secret 是否已配置。
5. Webhook 地址为 `https://shop.newzoe.cloud/api/telegram/webhook`。Telegram 请求必须带正确的 `X-Telegram-Bot-Api-Secret-Token` header。

顾客登录后打开“绑定 Telegram”，网站生成 32 字节随机、15 分钟有效的单次 token，数据库仅存 SHA-256 哈希。顾客点击深链或扫描二维码后，在机器人私聊中执行 `/start bind_TOKEN`。同一个 Telegram 私聊只归属一个商店账户；重新绑定会原子解除旧账户关系并绑定新账户。账户页支持解绑和重新绑定。

## 订单通知

- 登录顾客创建订单时写入 `orders.customer_id`；游客订单保持原流程且不发送私聊。
- 创建、支付成功、自动发货完成，以及人工状态变化分别使用 `order_id + event_key` 唯一键去重。
- Telegram 请求失败由队列重试并记录失败状态，不回滚下单、支付或发货。
- 文本不启用 HTML/Markdown 解析，避免商品名和卡密注入。长内容按 3500 字符分片，已发送分片用 `next_part` 续传。
- 最后一条消息包含“查看订单”按钮；创建事件还包含“前往支付”按钮。

## 运行检查

```bash
curl -fsS https://shop.newzoe.cloud/api/telegram/webhook/health
php artisan telegram:restock-test
php artisan queue:failed
supervisorctl status laravel-queue
```

生产日志、终端记录、提交和 PR 中均不应出现完整 Bot Token 或 webhook secret。
