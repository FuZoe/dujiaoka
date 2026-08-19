<?php

namespace Tests\Unit;

use App\Service\PaymentAvailability;
use App\Service\PayService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PaymentAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.newzoe_pay.wechat_night_pause_enabled' => true,
            'services.newzoe_pay.wechat_pause_start' => '22:00',
            'services.newzoe_pay.wechat_pause_end' => '06:00',
            'services.newzoe_pay.schedule_timezone' => 'Asia/Shanghai',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @dataProvider pauseBoundaryProvider
     */
    public function test_default_window_matches_exact_boundaries(string $time, bool $paused): void
    {
        $at = Carbon::parse($time, 'Asia/Shanghai');
        $availability = new PaymentAvailability();

        $this->assertSame($paused, $availability->isWechatPaused($at));
        $this->assertSame(!$paused, $availability->isAvailable('newzoe-wechat', $at));
    }

    public function test_other_payment_methods_remain_available_during_pause(): void
    {
        $at = Carbon::parse('2026-08-19 23:00:00', 'Asia/Shanghai');
        $availability = new PaymentAvailability();

        $this->assertTrue($availability->isAvailable('binancepay', $at));
        $this->assertTrue($availability->isAvailable('aliweb', $at));
        $this->assertFalse($availability->isAvailable('wxpay', $at));
        $this->assertFalse($availability->isAvailable('wescan', $at));
    }

    public function test_filter_preserves_non_wechat_gateways_and_collection_api(): void
    {
        $at = Carbon::parse('2026-08-20 02:00:00', 'Asia/Shanghai');
        $gateways = new Collection([
            ['id' => 1, 'pay_check' => 'newzoe-wechat'],
            ['id' => 2, 'pay_check' => 'binancepay'],
            ['id' => 3, 'pay_check' => 'aliweb'],
        ]);

        $filtered = (new PaymentAvailability())->filter($gateways, $at);

        $this->assertInstanceOf(Collection::class, $filtered);
        $this->assertSame([2, 3], $filtered->pluck('id')->all());
    }

    public function test_pause_can_be_disabled_without_changing_schedule(): void
    {
        config(['services.newzoe_pay.wechat_night_pause_enabled' => false]);
        $at = Carbon::parse('2026-08-19 23:00:00', 'Asia/Shanghai');

        $this->assertFalse((new PaymentAvailability())->isWechatPaused($at));
        $this->assertTrue((new PaymentAvailability())->isAvailable('newzoe-wechat', $at));
    }

    public function test_existing_order_keeps_its_selected_gateway_during_pause(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-19 23:00:00', 'Asia/Shanghai'));
        $service = new PayService(new PaymentAvailability());
        $wechat = (object) ['id' => 7, 'pay_check' => 'newzoe-wechat'];

        $this->assertTrue($service->isAvailableForOrder($wechat, (object) ['pay_id' => 7]));
        $this->assertFalse($service->isAvailableForOrder($wechat, (object) ['pay_id' => 8]));
        $this->assertFalse($service->isAvailableForOrder($wechat, null));
    }

    public static function pauseBoundaryProvider(): array
    {
        return [
            ['2026-08-19 21:59:59', false],
            ['2026-08-19 22:00:00', true],
            ['2026-08-20 05:59:59', true],
            ['2026-08-20 06:00:00', false],
        ];
    }
}
