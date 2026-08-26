<?php

return [
    'labels' => [
        'Goods' => '商品',
        'goods' => '商品',
        'english_storefront_content' => '英文商城內容',
    ],
    'fields' => [
        'actual_price' => '實際售價',
        'group_id' => '所屬分類',
        'api_hook' => '回調事件',
        'buy_prompt' => '購買提示',
        'description' => '商品描述',
        'gd_name' => '商品名稱',
        'gd_name_en' => '英文商品名稱',
        'gd_description' => '商品描述',
        'gd_description_en' => '英文商品描述',
        'gd_keywords' => '商品關鍵字',
        'gd_keywords_en' => '英文商品關鍵字',
        'in_stock' => '庫存',
        'ord' => '排序權重',
        'other_ipu_cnf' => '其他輸入框配置',
        'other_ipu_cnf_en' => '英文其他輸入框配置',
        'buy_prompt_en' => '英文購買提示',
        'description_en' => '英文商品詳情',
        'picture' => '商品圖片',
        'retail_price' => '零售價',
        'sales_volume' => '銷量',
        'type' => '商品類型',
        'buy_limit_num' => '限製單次購買最大數量',
        'wholesale_price_cnf' => '批發價配置',
        'automatic_delivery' => '自動發貨',
        'manual_processing' => '人工處理',
        'is_open' => '是否上架',
        'coupon_id' => '可用折扣碼'
    ],
    'options' => [
    ],
    'helps' => [
        'retail_price' => '可以不填寫，主要用於展示',
        'picture' => '可不上傳，為預設圖片',
        'in_stock' => '當商品類型為"人工處理"時，手動填寫的庫存數量才會生效。"自動發貨"類型的商品系統會自動識別庫存數量',
        'buy_limit_num' => '防止惡意刷庫存，0為不限製客戶單次下單最大數量',
        'other_ipu_cnf' => '格式為[唯一標識(英文)=輸入框名字=是否必填]，例如：填寫 line_account=Line賬戶=true 表示產品詳情頁會新增一個 [Line賬戶] 輸入框，客戶可在其中輸入 [Line賬戶]，true 為必填，false 為選填。（一行一個）',
        'english_storefront_content' => '以下內容只會展示在 /en 英文商城。留空時，英文商城會提示該商品的英文內容尚未填寫。',
        'other_ipu_cnf_en' => '格式與中文其他輸入框配置相同：保留每行左側的英文唯一標識，只翻譯輸入框名稱。例如 qq_account=QQ account=true。',
        'wholesale_price_cnf' => '例如：填寫 5=3 表示客戶購買 5 件或以上時，每件價格為 3 元。一行一個',

    ]
];
