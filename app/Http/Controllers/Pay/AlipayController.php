<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Service\AlipayKeyGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Yansongda\Pay\Pay;

class AlipayController extends PayController
{

    /**
     * 支付宝支付网关
     *
     * @param string $payway
     * @param string $orderSN
     */
    public function gateway(string $payway, string $orderSN)
    {
        try {
            // 加载网关
            $this->loadGateWay($orderSN, $payway);
            $config = $this->buildConfig($this->payGateway);
            $order = [
                'out_trade_no' => $this->order->order_sn,
                'total_amount' => (float)$this->order->actual_price,
                'subject' => $this->order->order_sn
            ];
            switch ($payway){
                case 'zfbf2f':
                case 'alipayscan':
                    try{
                        $result = Pay::alipay($config)->scan($order)->toArray();
                        $result['payname'] = $this->order->order_sn;
                        $result['actual_price'] = (float)$this->order->actual_price;
                        $result['orderid'] = $this->order->order_sn;
                        $result['jump_payuri'] = $result['qr_code'];
                        return $this->render('static_pages/qrpay', $result, __('dujiaoka.scan_qrcode_to_pay'));
                    } catch (\Throwable $e) {
                        return $this->paymentError($e, $payway);
                    }
                case 'aliweb':
                    try{
                        $result = Pay::alipay($config)->web($order);
                        return $result;
                    } catch (\Throwable $e) {
                        return $this->paymentError($e, $payway);
                    }
                case 'aliwap':
                    try{
                        $result = Pay::alipay($config)->wap($order);
                        return $result;
                    } catch (\Throwable $e) {
                        return $this->paymentError($e, $payway);
                    }
            }
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }
    }


    /**
     * 异步通知
     */
    public function notifyUrl(Request $request)
    {
        $orderSN = $request->input('out_trade_no');
        $order = $this->orderService->detailOrderSN($orderSN);
        if (!$order) {
            return 'error';
        }
        $payGateway = $this->payService->detailForNotification($order->pay_id);
        if (!$payGateway) {
            return 'error';
        }
        if($payGateway->pay_handleroute != '/pay/alipay'){
            return 'fail';
        }
        try{
            $pay = Pay::alipay($this->buildConfig($payGateway, false));
            // 验证签名
            $result = $pay->verify();
            if ($result->trade_status == 'TRADE_SUCCESS' || $result->trade_status == 'TRADE_FINISHED') {
                if ($order->manual_fulfilled_at) {
                    Log::info('Ignoring Alipay notification for manually fulfilled order', [
                        'order' => $result->out_trade_no,
                    ]);
                    return 'success';
                }
                // Alipay's asynchronous notification can arrive well after the
                // local checkout window (for example after a provider retry).
                // The SDK has already verified the signature and the amount,
                // so a successful notification is authoritative and must be
                // allowed to recover an order that the expiry job raced ahead
                // of. Without this override, a real payment stays expired and
                // the customer never reaches the normal delivery flow.
                $this->orderProcessService->completedOrder(
                    $result->out_trade_no,
                    $result->total_amount,
                    $result->trade_no,
                    true
                );
            }
            return 'success';
        } catch (\Throwable $exception) {
            Log::warning('Alipay notification verification failed', [
                'exception' => get_class($exception),
            ]);
            return 'fail';
        }
    }

    /**
     * Build and validate the RSA2 configuration used by the Alipay SDK.
     *
     * The admin form historically accepted the placeholder "商户号", which
     * produces a valid-looking form that Alipay rejects only after redirect.
     * Catching it here keeps the customer on the shop with an actionable error.
     *
     * @param mixed $gateway
     * @param bool $withCallbacks
     * @return array
     * @throws RuleValidationException
     */
    private function buildConfig($gateway, $withCallbacks = true)
    {
        $appId = trim((string) $gateway->merchant_id);
        if ($appId === '' || $appId === '商户号' || !preg_match('/^\d{10,32}$/', $appId)) {
            throw new RuleValidationException(__('dujiaoka.prompt.alipay_invalid_app_id'));
        }

        if (trim((string) $gateway->merchant_key) === '') {
            throw new RuleValidationException(__('dujiaoka.prompt.alipay_missing_public_key'));
        }
        if (trim((string) $gateway->merchant_pem) === '') {
            throw new RuleValidationException(__('dujiaoka.prompt.alipay_missing_private_key'));
        }
        if (app(AlipayKeyGuard::class)->isApplicationPublicKey(
            (string) $gateway->merchant_key,
            (string) $gateway->merchant_pem
        )) {
            throw new RuleValidationException(__('dujiaoka.prompt.alipay_application_public_key_misconfigured'));
        }

        $config = [
            'app_id' => $appId,
            'ali_public_key' => $gateway->merchant_key,
            'private_key' => $gateway->merchant_pem,
            // yansongda/pay otherwise defaults to /tmp/logs, which is not
            // writable by PHP-FPM in a fresh container. Keep SDK diagnostics
            // in Laravel's writable storage volume instead.
            'log' => [
                'file' => storage_path('logs/yansongda-pay.log'),
                'type' => 'daily',
                'max_files' => 7,
            ],
        ];

        if ($withCallbacks) {
            $config['notify_url'] = url($gateway->pay_handleroute . '/notify_url');
            $config['return_url'] = url('detail-order-sn', ['orderSN' => $this->order->order_sn]);
            $config['http'] = [
                'timeout' => 10.0,
                'connect_timeout' => 10.0,
            ];
        }

        return $config;
    }

    /**
     * Turn SDK failures into a useful customer-facing message without
     * rendering signed request data or key material in the error page.
     *
     * @param \Throwable $exception
     * @param string $payway
     * @return mixed
     */
    private function paymentError(\Throwable $exception, $payway)
    {
        $message = (string) $exception->getMessage();
        $permissionError = stripos($message, 'insufficient-isv-permissions') !== false;

        Log::warning('Alipay payment generation failed', [
            'payway' => $payway,
            'exception' => get_class($exception),
            'error_code' => $permissionError ? 'insufficient-isv-permissions' : 'generation_failed',
        ]);

        return $this->err($permissionError
            ? __('dujiaoka.prompt.alipay_permission_denied')
            : __('dujiaoka.prompt.alipay_generation_failed'));
    }



}
