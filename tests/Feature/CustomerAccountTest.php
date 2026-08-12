<?php

namespace Tests\Feature;

use App\Models\Customer;
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
}
