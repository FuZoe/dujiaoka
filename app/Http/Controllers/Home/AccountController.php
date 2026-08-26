<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\BaseController;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AccountController extends BaseController
{
    public function showLogin()
    {
        return $this->render('account/login', [], '登录');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = strtolower(trim($credentials['email']));

        // Synthetic Telegram addresses are internal identities, not web
        // accounts. Treat them like any other invalid login without revealing
        // whether such an address exists.
        if (Customer::isReservedTelegramEmail($credentials['email'])) {
            return back()->withErrors(['email' => '邮箱或密码不正确。'])
                ->withInput($request->only('email'));
        }

        if (!Auth::attempt($credentials, false)) {
            return back()->withErrors(['email' => '邮箱或密码不正确。'])->withInput($request->only('email'));
        }

        $request->session()->regenerate();
        return redirect()->intended(route('account'));
    }

    public function showRegister()
    {
        return $this->render('account/register', [], '注册账户');
    }

    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('customers', 'email'),
                function ($attribute, $value, $fail) {
                    if (Customer::isReservedTelegramEmail((string) $value)) {
                        $fail('该邮箱域名为系统保留地址。');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed'],
        ]);

        $customer = Customer::query()->create([
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        Auth::login($customer);
        $request->session()->regenerate();

        return redirect()->route('account');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }

    public function account(Request $request)
    {
        $customer = $request->user();
        $orders = $customer->orders()
            ->with(['telegramNotifications'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->render('account/index', compact('customer', 'orders'), '我的账户');
    }

    public function order(Request $request, int $id)
    {
        $order = $request->user()->orders()->whereKey($id)->firstOrFail();
        return $this->render('static_pages/orderinfo', ['orders' => [$order]], '订单详情');
    }
}
