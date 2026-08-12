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
        'SystemSetting' => '系統設定',
        'system_setting' => '系統設定',
        'base_setting' => '基本設定',
        'mail_setting' => '信箱服務',
        'order_push_setting' => '訂單推送配置',
        'geetest' => '極驗驗證',
    ],

    'fields' => [
        'title' => '網站標題',
        'text_logo' => '文字LOGO',
        'img_logo' => '圖片LOGO',
        'keywords' => '網站關鍵詞',
        'description' => '網站描述',
        'notice' => '站點公告',
        'footer' => '頁尾自訂代碼',
        'manage_email' => '管理員信箱',
        'is_open_anti_red' => '是否開啟Wechat/QQ防紅',
        'is_open_img_code' => '是否開啟圖形驗證碼',
        'is_open_search_pwd' => '是否開啟查詢密碼',
        'is_open_server_jiang' => '是否開啟server醬',
        'server_jiang_token' => 'server醬通訊token',
        'is_open_telegram_restock' => '補貨通知',
        'is_open_telegram_customer_order' => '顧客訂單私聊通知',
        'telegram_send_order_cards' => '私聊通知發送卡密',
        'telegram_userid' => 'Telegram目標頻道',
        'telegram_bot_token' => 'Telegram通訊token',
        'template' => '站點模板',
        'language' => '站點語言',
        'order_expire_time' => '訂單逾期時間(分鐘)',

        'driver' => '信箱驅動',
        'host' => 'smtp伺服器地址',
        'port' => '通訊埠',
        'username' => '賬戶',
        'password' => '密碼',
        'encryption' => '協議',
        'from_address' => '發件地址',
        'from_name' => '發件名稱',

        'geetest_id' => '極驗id',
        'geetest_key' => '極驗key',
        'is_open_geetest' => '是否開啟極驗',
    ],
    'options' => [
    ],
    'helps' => [
        'is_open_telegram_restock' => '批量匯入卡密使庫存增加時發送，每個匯入批次最多一條。',
        'is_open_telegram_customer_order' => '僅向已綁定帳戶對應的 Telegram 私聊發送該顧客自己的訂單事件；目標頻道始終只接收補貨公告。',
        'telegram_send_order_cards' => '開啟後，自動發貨完成通知會包含卡密；關閉後僅發送狀態和受保護的訂單連結。',
        'telegram_channel' => '頻道設定步驟：1. 將機器人加入目標頻道並設為管理員，至少授予「發佈訊息」權限。2. 公開頻道填寫 @channel_username（包含 @）。3. 私人頻道填寫 -100 開頭的數值 chat_id；可先給機器人發送訊息，再呼叫 Bot API getUpdates 查看 channel_post/chat/id，或把頻道訊息轉發給可查詢原始 chat_id 的機器人取得。請勿填寫個人或群組 chat_id。',
    ],
    'rule_messages' => [
        'save_system_setting_success' => '系統配置套用成功！',
        'change_reboot_php_worker' => '修改部分配置需要重新啓動[supervisor]或php進程管理工具才會生效，例如信箱服務，server醬等。'
    ]
];
