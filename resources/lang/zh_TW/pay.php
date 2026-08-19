<?php

return [
    'labels' => [
        'Pay' => '支付通道',
        'pay' => '支付通道',
    ],
    'fields' => [
        'merchant_id' => '商戶 ID',
        'merchant_key' => '商戶 KEY',
        'merchant_pem' => '商戶金鑰',
        'pay_check' => '支付標識',
        'pay_client' => '支付場景',
        'pay_handleroute' => '支付處理路由',
        'pay_method' => '支付方式',
        'pay_name' => '支付名稱',
        'is_open' => '是否啟用',
        'method_jump' => '跳躍',
        'method_scan' => '掃碼',
        'pay_client_pc' => '計算機PC',
        'pay_client_mobile' => '行動電話',
        'pay_client_all' => '通用',
    ],
    'options' => [
    ],
    'alipay' => [
        'errors' => [
            'application_public_key' => '這裡誤填了應用公鑰。請複製支付寶開放平台顯示的「支付寶公鑰」。',
        ],
        'helps' => [
            'merchant_id' => '填寫支付寶開放平台應用 APPID（通常為 16 位數字），不要填寫支付寶帳號或「商戶號」。同時確認應用已簽約當前支付產品。',
            'merchant_key' => '填寫開放平台「介面加簽方式」頁面顯示的支付寶公鑰正文。不要填寫應用公鑰，也不要填寫憑證檔名。',
            'merchant_pem' => '填寫該應用對應的 RSA2 應用私鑰正文。支付寶密鑰工具匯出的單行內容可直接貼上。',
        ],
    ],
];
