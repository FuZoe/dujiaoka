<?php

namespace Tests\Unit;

use App\Models\WarzoneSupplierPurchase;
use App\Models\WarzoneSupplierSetting;
use Tests\TestCase;

class WarzoneSupplierModelsTest extends TestCase
{
    public function test_api_key_is_encrypted_hidden_and_bound_to_service_test(): void
    {
        $setting = new WarzoneSupplierSetting([
            'goods_id' => 16,
            'service_id' => 'S_01',
            'unit_cost_usd' => '0.4000',
            'enabled' => true,
        ]);
        $setting->setApiKey('WAR_TEST_SECRET');

        $this->assertNotSame('WAR_TEST_SECRET', $setting->api_key_encrypted);
        $this->assertSame('WAR_TEST_SECRET', $setting->getApiKey());
        $this->assertArrayNotHasKey('api_key_encrypted', $setting->toArray());
        $this->assertSame('not_tested', $setting->connectionTestStatus());

        $setting->markConnectionTest(true);
        $this->assertTrue($setting->hasSuccessfulConnectionTest());
        $this->assertTrue($setting->isReady());
        $this->assertSame('passed', $setting->connectionTestStatus());

        $setting->service_id = 'S_02';
        $this->assertFalse($setting->hasSuccessfulConnectionTest());
        $this->assertFalse($setting->isReady());
        $this->assertSame('stale', $setting->connectionTestStatus());
    }

    public function test_replacing_or_removing_api_key_resets_connection_test(): void
    {
        $setting = new WarzoneSupplierSetting([
            'service_id' => 'S_01',
            'unit_cost_usd' => '0.4000',
            'enabled' => true,
        ]);
        $setting->setApiKey('WAR_FIRST')->markConnectionTest(true);

        $setting->setApiKey('WAR_SECOND');
        $this->assertFalse($setting->connection_test_ok);
        $this->assertNull($setting->tested_credentials_hash);
        $this->assertSame('not_tested', $setting->connectionTestStatus());

        $setting->setApiKey('');
        $this->assertFalse($setting->hasApiKey());
        $this->assertSame('not_configured', $setting->connectionTestStatus());
    }

    public function test_snapshot_fields_are_recorded_without_raw_payload(): void
    {
        $setting = new WarzoneSupplierSetting();
        $setting->recordSnapshot('12.75', [
            'stock' => 91,
            'orderable' => true,
            'price' => '0.4',
            'products' => ['MUST_NOT_BE_STORED'],
        ]);

        $this->assertSame('12.75', $setting->last_balance_usd);
        $this->assertSame(91, $setting->last_supplier_stock);
        $this->assertTrue($setting->last_supplier_orderable);
        $this->assertSame('0.4', $setting->last_product_price_usd);
        $this->assertNotNull($setting->last_snapshot_at);
        $this->assertStringNotContainsString('MUST_NOT_BE_STORED', json_encode($setting->getAttributes()));
    }

    public function test_purchase_products_are_encrypted_and_hidden(): void
    {
        $purchase = new WarzoneSupplierPurchase([
            'quantity' => 2,
            'status' => WarzoneSupplierPurchase::STATUS_STOCKED,
            'products' => ['PRODUCT_ONE', 'PRODUCT_TWO'],
        ]);

        $this->assertStringNotContainsString('PRODUCT_ONE', $purchase->products_encrypted);
        $this->assertSame(['PRODUCT_ONE', 'PRODUCT_TWO'], $purchase->getProducts());
        $this->assertSame(['PRODUCT_ONE', 'PRODUCT_TWO'], $purchase->products);
        $this->assertTrue($purchase->hasProducts());
        $this->assertArrayNotHasKey('products_encrypted', $purchase->toArray());
        $this->assertFalse($purchase->isTerminal());

        $purchase->status = WarzoneSupplierPurchase::STATUS_COMPLETED;
        $this->assertTrue($purchase->isTerminal());

        $purchase->status = WarzoneSupplierPurchase::STATUS_AMBIGUOUS;
        $this->assertTrue($purchase->isTerminal());
    }

    public function test_invalid_encrypted_values_are_not_exposed(): void
    {
        $setting = new WarzoneSupplierSetting();
        $setting->api_key_encrypted = 'invalid-ciphertext';
        $this->assertSame('', $setting->getApiKey());

        $purchase = new WarzoneSupplierPurchase();
        $purchase->products_encrypted = 'invalid-ciphertext';
        $this->assertSame([], $purchase->getProducts());
    }

    public function test_non_string_products_are_rejected_before_encryption(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new WarzoneSupplierPurchase())->setProducts([['nested-secret']]);
    }
}
