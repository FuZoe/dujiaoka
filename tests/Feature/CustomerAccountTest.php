<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\TelegramBinding;
use Illuminate\Support\Facades\Hash;
use App\Http\Middleware\DujiaoBoot;
use Tests\Support\BuildsTelegramTables;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use BuildsTelegramTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(DujiaoBoot::class);
        $this->buildTelegramTables();
        cache()->forever('system-setting', ['template' => 'unicorn', 'language' => 'zh_CN']);
    }

    public function test_customer_can_register_with_normalized_email_and_hashed_password(): void
    {
        $response = $this->post('/register', [
            'email' => ' Customer@Example.Test ',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/account');
        $customer = Customer::query()->firstOrFail();
        $this->assertSame('customer@example.test', $customer->email);
        $this->assertTrue(Hash::check('correct-horse-battery', $customer->password));
        $this->assertAuthenticatedAs($customer);
    }

    public function test_reserved_telegram_addresses_cannot_be_registered_or_used_to_log_in(): void
    {
        $response = $this->from('/register')->post('/register', [
            'email' => 'Telegram-12345@Telegram.NewZoe.Cloud',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertSame(0, Customer::query()->count());

        // Rows created before this guard must not become web-login accounts.
        $customer = Customer::query()->create([
            'email' => 'telegram-12345@telegram.newzoe.cloud',
            'password' => bcrypt('correct-horse-battery'),
        ]);
        $this->from('/login')->post('/login', [
            'email' => $customer->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->from('/login')->post('/login', [
                'email' => 'missing@example.test',
                'password' => 'incorrect-password',
            ])->assertStatus(302);
        }

        $this->post('/login', [
            'email' => 'missing@example.test',
            'password' => 'incorrect-password',
        ])->assertStatus(429);
    }

    public function test_telegram_binding_redirects_with_notice_when_bot_username_is_missing(): void
    {
        config(['services.telegram.bot_username' => ' @ ']);
        $customer = Customer::query()->create([
            'email' => 'binding-unavailable@example.test',
            'password' => bcrypt('test-password'),
        ]);

        $response = $this->actingAs($customer)->get('/account/telegram/bind');

        $response->assertRedirect('/account');
        $response->assertSessionHas('status', 'Telegram 绑定服务正在配置中，请稍后再试。');
        $this->assertSame(0, TelegramBinding::query()->count());
    }

    public function test_telegram_binding_page_uses_configured_bot_username(): void
    {
        config(['services.telegram.bot_username' => '@newzoe_order_bot']);
        $customer = Customer::query()->create([
            'email' => 'binding-ready@example.test',
            'password' => bcrypt('test-password'),
        ]);

        $response = $this->actingAs($customer)->get('/account/telegram/bind');

        $response->assertOk();
        $response->assertSee('https://t.me/newzoe_order_bot?start=bind_', false);
        $this->assertSame(1, TelegramBinding::query()->count());
    }

    public function test_guest_navigation_exposes_telegram_binding_and_preserves_the_intended_page(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('绑定 Telegram');
        $response->assertSee('href="'.route('telegram.bind').'"', false);

        $this->get(route('telegram.bind'))->assertRedirect(route('login'));
    }

    public function test_bound_customer_navigation_exposes_telegram_management(): void
    {
        $customer = Customer::query()->create([
            'email' => 'bound-navigation@example.test',
            'password' => bcrypt('test-password'),
        ]);
        $customer->forceFill([
            'telegram_chat_id' => '3010',
            'telegram_bound_at' => now(),
        ])->save();

        $response = $this->actingAs($customer)->get('/account');

        $response->assertOk();
        $response->assertSee('管理 Telegram');
        $response->assertSee('href="'.route('account').'#telegram-account"', false);
    }
}
