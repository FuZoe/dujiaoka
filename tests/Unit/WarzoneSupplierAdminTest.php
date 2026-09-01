<?php

namespace Tests\Unit;

use App\Admin\Controllers\WarzoneSupplierSettingController;
use App\Admin\Forms\WarzoneSupplierSettingForm;
use App\Models\Goods;
use App\Models\WarzoneSupplierSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class WarzoneSupplierAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('warzone_supplier_settings');
        Schema::dropIfExists('goods');
        Schema::create('goods', function (Blueprint $table) {
            $table->increments('id');
            $table->string('gd_name');
            $table->unsignedTinyInteger('type')->default(Goods::AUTOMATIC_DELIVERY);
            $table->timestamps();
            $table->softDeletes();
        });
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

        Goods::query()->insert([
            'id' => 16,
            'gd_name' => 'Time-sensitive product',
            'type' => Goods::AUTOMATIC_DELIVERY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_blank_api_key_preserves_encrypted_key_and_connection_test(): void
    {
        $setting = WarzoneSupplierSetting::currentForGoods(16);
        $setting->setApiKey('WAR_KEEP_THIS_KEY')
            ->markConnectionTest(true)
            ->save();

        (new WarzoneSupplierSettingForm())->handle([
            'goods_id' => 16,
            'api_key' => '',
            'service_id' => 'S_01',
            'unit_cost_usd' => '0.5500',
            'enabled' => 1,
        ]);

        $setting->refresh();
        $this->assertSame('WAR_KEEP_THIS_KEY', $setting->getApiKey());
        $this->assertTrue($setting->hasSuccessfulConnectionTest());
        $this->assertTrue($setting->enabled);
        $this->assertSame('0.55', (string) $setting->unit_cost_usd);
    }

    public function test_changed_api_key_is_encrypted_and_disables_until_retested(): void
    {
        $setting = WarzoneSupplierSetting::currentForGoods(16);
        $setting->setApiKey('WAR_OLD_KEY')
            ->recordSnapshot('4.8', ['stock' => 12, 'orderable' => true, 'price' => '0.5'])
            ->markConnectionTest(true)
            ->save();

        (new WarzoneSupplierSettingForm())->handle([
            'goods_id' => 16,
            'api_key' => 'WAR_NEW_KEY',
            'service_id' => 'S_01',
            'unit_cost_usd' => '0.4000',
            'enabled' => 1,
        ]);

        $setting->refresh();
        $this->assertSame('WAR_NEW_KEY', $setting->getApiKey());
        $this->assertStringNotContainsString('WAR_NEW_KEY', (string) $setting->api_key_encrypted);
        $this->assertFalse($setting->enabled);
        $this->assertFalse($setting->connection_test_ok);
        $this->assertNull($setting->last_balance_usd);
        $this->assertNull($setting->last_supplier_orderable);
    }

    public function test_status_uses_higher_live_price_and_never_renders_api_key(): void
    {
        $setting = new WarzoneSupplierSetting([
            'goods_id' => 16,
            'service_id' => 'S_01',
            'unit_cost_usd' => '0.4000',
            'enabled' => true,
            'last_balance_usd' => '1.2000',
            'last_supplier_stock' => 10,
            'last_supplier_orderable' => true,
            'last_product_price_usd' => '0.5000',
        ]);
        $setting->setApiKey('WAR_MUST_NOT_RENDER');

        $method = new ReflectionMethod(WarzoneSupplierSettingController::class, 'buildStatus');
        $method->setAccessible(true);
        $status = $method->invoke(new WarzoneSupplierSettingController(), $setting, 3);

        $this->assertSame('0.5000', $status['effectiveCost']);
        $this->assertSame(2, $status['balanceCapacity']);
        $this->assertSame(10, $status['externalStock']);
        $this->assertSame(13, $status['displayStock']);

        $goods = new Goods();
        $goods->setRawAttributes(['id' => 16, 'gd_name' => 'Time-sensitive product']);
        $html = view('admin.warzone-supplier.status', array_merge($status, [
            'goods' => $goods,
            'goodsOptions' => [16 => '#16 Time-sensitive product'],
            'setting' => $setting,
            'purchases' => collect(),
        ]))->render();
        $this->assertStringNotContainsString('WAR_MUST_NOT_RENDER', $html);
        $this->assertStringContainsString(
            "\$this->password('api_key'",
            file_get_contents(app_path('Admin/Forms/WarzoneSupplierSettingForm.php'))
        );
    }

    public function test_non_orderable_supplier_stock_remains_visible_for_estimation(): void
    {
        $setting = new WarzoneSupplierSetting([
            'unit_cost_usd' => '0.4000',
            'last_balance_usd' => '8.0000',
            'last_supplier_stock' => 20,
            'last_supplier_orderable' => false,
            'last_product_price_usd' => '0.4000',
        ]);
        $method = new ReflectionMethod(WarzoneSupplierSettingController::class, 'buildStatus');
        $method->setAccessible(true);
        $status = $method->invoke(new WarzoneSupplierSettingController(), $setting, 1);

        $this->assertSame(20, $status['externalStock']);
        $this->assertSame(21, $status['displayStock']);
    }
}
