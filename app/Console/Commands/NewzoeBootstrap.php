<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class NewzoeBootstrap extends Command
{
    protected $signature = 'newzoe:bootstrap';
    protected $description = 'Install and configure the NewZoe shop';

    public function handle()
    {
        if (!Schema::hasTable('orders')) {
            $this->info('Importing the base database schema...');
            DB::unprepared(file_get_contents(database_path('sql/install.sql')));
        }

        $password = (string) env('SHOP_ADMIN_PASSWORD', '');
        if (strlen($password) < 16) {
            $this->error('SHOP_ADMIN_PASSWORD must contain at least 16 characters.');
            return 1;
        }

        DB::table('admin_users')->where('username', 'admin')->update([
            'name' => 'NewZoe Administrator',
            'password' => Hash::make($password),
            'updated_at' => now(),
        ]);

        DB::table('pays')->where('pay_check', '!=', 'newzoe-wechat')->update(['is_open' => 0]);
        DB::table('pays')->where('pay_check', 'newzoe-wechat')->update([
            'is_open' => 1,
            'pay_client' => 3,
            'pay_handleroute' => '/pay/newzoe',
            'updated_at' => now(),
        ]);

        $defaultSettings = [
            'title' => 'NewZoe 数字商店',
            'text_logo' => 'NewZoe',
            'keywords' => '数字商品,自动发货,NewZoe',
            'description' => 'NewZoe 数字商品自动发货商店',
            'template' => 'unicorn',
            'language' => 'zh_CN',
            'manage_email' => '',
            'order_expire_time' => 15,
            'is_open_anti_red' => 0,
            'is_open_img_code' => 0,
            'is_open_search_pwd' => 0,
            'is_open_google_translate' => 0,
            'is_open_server_jiang' => 0,
            'is_open_telegram_push' => 0,
            'is_open_telegram_restock' => 0,
            'is_open_bark_push' => 0,
            'is_open_qywxbot_push' => 0,
            'is_open_geetest' => 0,
            'notice' => '下单后请按收银台显示的金额付款，到账后系统会自动发货。',
            'footer' => '数字商品自动发货服务',
        ];
        $savedSettings = Cache::get('system-setting', []);
        Cache::forever(
            'system-setting',
            array_merge($defaultSettings, is_array($savedSettings) ? $savedSettings : [])
        );

        if (filter_var(env('NEWZOE_CREATE_DEMO', true), FILTER_VALIDATE_BOOLEAN)) {
            $this->createDemoProduct();
        }

        file_put_contents(base_path('install.lock'), "installed\n");
        $this->info('NewZoe shop is configured.');
        return 0;
    }

    private function createDemoProduct(): void
    {
        $groupId = DB::table('goods_group')->where('gp_name', '支付测试')->value('id');
        if (!$groupId) {
            $groupId = DB::table('goods_group')->insertGetId([
                'gp_name' => '支付测试',
                'is_open' => 1,
                'ord' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $goodsId = DB::table('goods')->where('gd_keywords', 'newzoe-payment-test')->value('id');
        $goods = [
            'group_id' => $groupId,
            'gd_name' => '支付链路测试商品',
            'gd_description' => '用于验证一分钱支付、回调与自动发货',
            'gd_keywords' => 'newzoe-payment-test',
            'picture' => null,
            'retail_price' => '0.01',
            'actual_price' => '0.01',
            'in_stock' => 1,
            'sales_volume' => 0,
            'ord' => 100,
            'buy_limit_num' => 1,
            'buy_prompt' => '这是支付链路测试商品，付款金额为 0.01 元。',
            'description' => '<p>支付完成后，系统将自动显示测试卡密。</p>',
            'type' => 1,
            'wholesale_price_cnf' => null,
            'other_ipu_cnf' => null,
            'api_hook' => null,
            'is_open' => 1,
            'updated_at' => now(),
        ];
        if ($goodsId) {
            DB::table('goods')->where('id', $goodsId)->update($goods);
        } else {
            $goods['created_at'] = now();
            $goodsId = DB::table('goods')->insertGetId($goods);
        }

        if (!DB::table('carmis')->where('goods_id', $goodsId)->where('status', 1)->exists()) {
            DB::table('carmis')->insert([
                'goods_id' => $goodsId,
                'status' => 1,
                'is_loop' => 1,
                'carmi' => 'NEWZOE-PAYMENT-TEST-SUCCESS',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
