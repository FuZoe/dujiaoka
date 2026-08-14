<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

return [
    'labels' => [
        'SystemSetting' => '系统设置',
        'system_setting' => '系统设置',
        'base_setting' => '基本设置',
        'mail_setting' => '邮件服务',
        'order_push_setting' => '订单推送配置',
        'geetest' => '极验验证',
    ],

    'fields' => [
        'title' => '网站标题',
        'text_logo' => '文字LOGO',
        'img_logo' => '图片LOGO',
        'keywords' => '网站关键词',
        'description' => '网站描述',
        'notice' => '站点公告',
        'footer' => '页脚自定义代码',
        'manage_email' => '管理员邮箱',
        'is_open_anti_red' => '是否开启微信/QQ防红',
        'is_open_img_code' => '是否开启图形验证码',
        'is_open_search_pwd' => '是否开启查询密码',
        'is_open_google_translate' => '是否开启google翻译',

        'is_open_server_jiang' => '是否开启server酱',
        'server_jiang_token' => 'server酱通讯token',
        'is_open_telegram_restock' => '补货通知',
        'is_open_telegram_customer_order' => '顾客订单私聊通知',
        'telegram_send_order_cards' => '私聊通知发送卡密',
        'telegram_userid' => 'Telegram补货目标',
        'telegram_bot_token' => 'Telegram通讯token',
		'is_open_bark_push' => '是否开启Bark推送',
		'is_open_bark_push_url' => '是否推送订单URL',
		'bark_server' => 'Bark服务器',
		'bark_token' => 'Bark通讯Token',
		'is_open_qywxbot_push' => '是否开启企业微信Bot推送',
		'qywxbot_key' => '企业微信Bot通讯Key',

        'template' => '站点模板',
        'language' => '站点语言',
        'order_expire_time' => '订单过期时间(分钟)',

        'driver' => '邮件驱动',
        'host' => 'smtp服务器地址',
        'port' => '端口',
        'username' => '账号',
        'password' => '密码',
        'encryption' => '协议',
        'from_address' => '发件地址',
        'from_name' => '发件名称',

        'geetest_id' => '极验id',
        'geetest_key' => '极验key',
        'is_open_geetest' => '是否开启极验',
    ],
    'options' => [
    ],
    'helps' => [
        'is_open_telegram_restock' => '批量导入卡密使库存增加时发送，每个导入批次最多一条。',
        'is_open_telegram_customer_order' => '仅向已绑定账户对应的 Telegram 私聊发送该顾客自己的订单事件；目标频道始终只接收补货公告。',
        'telegram_send_order_cards' => '开启后，自动发货完成通知会包含卡密；关闭后仅发送状态和受保护的订单链接。',
        'telegram_channel' => '目标可填写：1. 公开频道的 @channel_username 或 t.me/channel_username；2. 私有频道的 -100 开头 chat_id（先将机器人设为频道管理员并授予发布权限）；3. 管理员私聊 chat_id（正数）。频道 ID 可通过 Bot API 的 channel_post.chat.id 获取。',
    ],
    'rule_messages' => [
        'save_system_setting_success' => '系统配置保存成功！',
        'change_reboot_php_worker' => '修改部分配置需要重启[supervisor]或php进程管理工具才会生效，例如邮件服务，server酱等。'
    ]
];
