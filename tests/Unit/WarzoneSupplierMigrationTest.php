<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarzoneSupplierMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('warzone_supplier_purchases');
        Schema::dropIfExists('warzone_supplier_settings');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('warzone_supplier_purchases');
        Schema::dropIfExists('warzone_supplier_settings');
        parent::tearDown();
    }

    public function test_migration_creates_disabled_default_without_api_key(): void
    {
        require_once database_path('migrations/2026_09_01_010000_create_warzone_supplier_tables.php');
        $migration = new \CreateWarzoneSupplierTables();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('warzone_supplier_settings', [
            'goods_id',
            'service_id',
            'api_key_encrypted',
            'unit_cost_usd',
            'enabled',
            'last_supplier_orderable',
        ]));
        $this->assertTrue(Schema::hasColumns('warzone_supplier_purchases', [
            'order_id',
            'provider_order_id',
            'status',
            'products_encrypted',
        ]));

        $setting = DB::table('warzone_supplier_settings')->where('goods_id', 16)->first();
        $this->assertNotNull($setting);
        $this->assertSame('S_01', $setting->service_id);
        $this->assertSame(0.4, (float) $setting->unit_cost_usd);
        $this->assertSame(0, (int) $setting->enabled);
        $this->assertNull($setting->api_key_encrypted);

        $migration->down();
        $this->assertFalse(Schema::hasTable('warzone_supplier_purchases'));
        $this->assertFalse(Schema::hasTable('warzone_supplier_settings'));
    }
}
