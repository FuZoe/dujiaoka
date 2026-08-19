<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Service;


use App\Models\Pay;

class PayService
{

    /** @var PaymentAvailability */
    private $availability;

    public function __construct(PaymentAvailability $availability = null)
    {
        $this->availability = $availability ?: app(PaymentAvailability::class);
    }

    /**
     * 加载支付网关
     *
     * @param string|int $payClient 支付场景客户端
     * @return array|null
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function pays(string $payClient = Pay::PAY_CLIENT_PC): ?array
    {
        $payGateway = Pay::query()
            ->whereIn('pay_client', [$payClient, Pay::PAY_CLIENT_ALL])
            ->where('is_open', Pay::STATUS_OPEN)
            ->get();
        $payGateway = $this->availability->filter($payGateway);
        return $payGateway ? $payGateway->toArray() : null;
    }

    /**
     * 通过支付标识获得支付配置
     *
     * @param string $check 支付标识
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function detailByCheck(string $check)
    {
        $gateway = Pay::query()
            ->where('pay_check', $check)
            ->where('is_open', Pay::STATUS_OPEN)
            ->first();
        return $gateway;
    }

    /**
     * 通过id查询支付网关
     *
     * @param int $id 支付网关id
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function detail(int $id)
    {
        $gateway = Pay::query()
            ->where('id', $id)
            ->where('is_open', Pay::STATUS_OPEN)
            ->first();
        return $gateway;
    }

    /**
     * Load the configuration attached to an existing order callback. Closing
     * a gateway stops new checkouts but must not discard a payment already in
     * flight.
     */
    public function detailForNotification(int $id)
    {
        return Pay::query()->where('id', $id)->first();
    }

    /**
     * Check a gateway at the point where a customer is about to use it.
     * Notification handlers use detailForNotification() so a payment that was
     * accepted before a gateway was closed can still be reconciled.
     */
    public function isAvailable($gateway): bool
    {
        return $gateway && $this->availability->isAvailable($gateway);
    }

    /**
     * Keep a gateway usable for an order that selected it before a scheduled
     * pause. The pause prevents new selections without stranding an existing
     * customer part-way through an unexpired checkout.
     *
     * @param mixed $gateway
     * @param mixed $order
     */
    public function isAvailableForOrder($gateway, $order): bool
    {
        if (!$gateway) {
            return false;
        }
        if ($this->isAvailable($gateway)) {
            return true;
        }

        return $order
            && isset($gateway->id, $order->pay_id)
            && (int) $gateway->id > 0
            && (int) $gateway->id === (int) $order->pay_id;
    }

}
