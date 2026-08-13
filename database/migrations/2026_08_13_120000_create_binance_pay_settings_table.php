<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateBinancePaySettingsTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('binance_pay_settings')) {
            Schema::create('binance_pay_settings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->text('api_key_encrypted')->nullable();
                $table->text('api_secret_encrypted')->nullable();
                $table->string('receiver_binance_id', 64)->nullable();
                $table->text('receive_qr_payload')->nullable();
                $table->string('receive_qr_image_path', 255)->nullable();
                $table->decimal('cny_per_usdt', 18, 8)->default(7.2);
                $table->boolean('enabled')->default(false);
                $table->boolean('connection_test_ok')->default(false);
                $table->char('tested_credentials_hash', 64)->nullable();
                $table->timestamp('last_tested_at')->nullable();
                $table->timestamp('last_polled_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        } elseif (!Schema::hasColumn('binance_pay_settings', 'tested_credentials_hash')) {
            Schema::table('binance_pay_settings', function (Blueprint $table) {
                $table->char('tested_credentials_hash', 64)->nullable()->after('connection_test_ok');
            });
        }

        if (!DB::table('binance_pay_settings')->where('id', 1)->exists()) {
            DB::table('binance_pay_settings')->insert([
                'id' => 1,
                'receive_qr_payload' => config('services.binance_pay.receive_qr_payload'),
                'cny_per_usdt' => config('services.binance_pay.cny_per_usdt', '7.20000000'),
                'enabled' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        if (Schema::hasTable('pays') && !DB::table('pays')->where('pay_check', 'binancepay')->whereNull('deleted_at')->exists()) {
            DB::table('pays')->insert([
                    'pay_name' => '币安支付',
                    'pay_check' => 'binancepay',
                    'pay_method' => 1,
                    'pay_client' => 3,
                    'merchant_id' => 'Binance Pay',
                    'merchant_key' => '',
                    'merchant_pem' => '由币安支付专用配置提供',
                    'pay_handleroute' => '/pay/binance',
                    'is_open' => 0,
                    'updated_at' => now(),
                    'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pays')) {
            DB::table('pays')->where('pay_check', 'binancepay')->delete();
        }
        Schema::dropIfExists('binance_pay_attempts');
        Schema::dropIfExists('binance_pay_settings');
    }
}
