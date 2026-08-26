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
        return $this->render('account/login', [], __('store.page.login'));
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
            return back()->withErrors(['email' => __('store.auth.invalid_credentials')])
                ->withInput($request->only('email'));
        }

        if (!Auth::attempt($credentials, false)) {
            return back()->withErrors(['email' => __('store.auth.invalid_credentials')])->withInput($request->only('email'));
        }

        $request->session()->regenerate();
        return redirect()->intended(shop_route('account'));
    }

    public function showRegister()
    {
        return $this->render('account/register', [], __('store.page.register'));
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
                        $fail(__('store.auth.reserved_email'));
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

        return redirect()->to(shop_route('account'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect(shop_url('/'));
    }

    public function account(Request $request)
    {
        $customer = $request->user();
        $orders = $customer->orders()
            ->with(['goods', 'pay', 'telegramNotifications'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->render('account/index', compact('customer', 'orders'), __('store.page.account'));
    }

    public function order(Request $request, int $id)
    {
        $order = $request->user()->orders()
            ->with(['goods', 'pay'])
            ->whereKey($id)
            ->firstOrFail();
        return $this->render('static_pages/orderinfo', ['orders' => [$order]], __('store.page.order_detail'));
    }
}
