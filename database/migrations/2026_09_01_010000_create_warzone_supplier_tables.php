<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateWarzoneSupplierTables extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('warzone_supplier_settings')) {
            Schema::create('warzone_supplier_settings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('goods_id')->unique();
                $table->string('service_id', 64);
                $table->text('api_key_encrypted')->nullable();
                $table->decimal('unit_cost_usd', 12, 4)->default(0.4);
                $table->boolean('enabled')->default(false);
                $table->boolean('connection_test_ok')->default(false);
                $table->char('tested_credentials_hash', 64)->nullable();
                $table->decimal('last_balance_usd', 14, 4)->nullable();
                $table->unsignedInteger('last_supplier_stock')->nullable();
                $table->boolean('last_supplier_orderable')->nullable();
                $table->decimal('last_product_price_usd', 12, 4)->nullable();
                $table->timestamp('last_snapshot_at')->nullable();
                $table->timestamp('last_tested_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('warzone_supplier_purchases')) {
            Schema::create('warzone_supplier_purchases', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('setting_id')->index();
                $table->integer('goods_id')->index();
                $table->unsignedBigInteger('order_id')->unique();
                $table->string('order_sn', 150)->index();
                $table->string('provider_order_id', 128)->nullable()->unique();
                $table->string('service_id', 64);
                $table->unsignedInteger('quantity')->default(1);
                $table->string('status', 20)->default('queued');
                $table->decimal('unit_cost_usd', 12, 4)->nullable();
                $table->decimal('total_cost_usd', 14, 4)->nullable();
                $table->longText('products_encrypted')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('stocked_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'updated_at'], 'warzone_purchase_status_idx');
            });
        }

        if (!DB::table('warzone_supplier_settings')->where('goods_id', 16)->exists()) {
            DB::table('warzone_supplier_settings')->insert([
                'goods_id' => 16,
                'service_id' => 'S_01',
                'unit_cost_usd' => '0.4000',
                'enabled' => false,
                'connection_test_ok' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warzone_supplier_purchases');
        Schema::dropIfExists('warzone_supplier_settings');
    }
}
