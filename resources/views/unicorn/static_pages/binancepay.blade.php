@extends('unicorn.layouts.default')

@php
    $expectedAmount = $attempt->expected_usdt;
    $expiresAt = optional($attempt->expires_at)->toIso8601String();
    // The payload is validated and stored by the admin form. The uploaded image
    // remains an admin-side reference only, so the checkout always renders a
    // clean QR code for this exact, verified account.
    $qrPayload = trim((string) $config->receive_qr_payload);
    $settlementGraceSeconds = max(0, (int) config('services.binance_pay.settlement_grace_seconds', 300));
    $settlementPollBufferSeconds = max(4, (int) config('services.binance_pay.poll_interval_seconds', 60));
@endphp

@section('content')
<main class="store-main order-page binance-pay-page">
    <div class="store-shell binance-pay-shell">
        <section class="order-sheet binance-pay-sheet">
            <header class="binance-pay-head">
                <div>
                    <div class="eyebrow">{{ __('pay.binance.checkout.eyebrow') }}</div>
                    <h1>{{ __('pay.binance.checkout.title') }}</h1>
                    <p>{{ __('pay.binance.checkout.subtitle') }}</p>
                </div>
                <div class="binance-pay-expiry">
                    <span>{{ __('pay.binance.checkout.remaining') }}</span>
                    <strong id="binance-countdown">--:--</strong>
                </div>
            </header>

            <div class="binance-pay-layout">
                <div class="binance-qr-column">
                    <div class="binance-qr-frame">
                        @if($qrPayload !== '')
                            <img
                                src="data:image/png;base64,{!! base64_encode(QrCode::format('png')->size(320)->margin(1)->generate($qrPayload)) !!}"
                                alt="{{ __('pay.binance.checkout.qr_alt') }}"
                                width="320"
                                height="320"
                                decoding="async"
                            >
                        @else
                            <span class="binance-qr-missing">{{ __('pay.binance.checkout.qr_missing') }}</span>
                        @endif
                    </div>
                    @if($qrPayload !== '')
                        <a class="primary-action binance-open-action" href="{{ $qrPayload }}" rel="noopener">
                            {{ __('pay.binance.checkout.open_app') }}
                        </a>
                    @endif
                </div>

                <div class="binance-payment-details">
                    <div class="binance-exact-warning">
                        <strong>{{ __('pay.binance.checkout.warning_title') }}</strong>
                        <p>{{ __('pay.binance.checkout.warning_body') }}</p>
                    </div>

                    <div class="binance-amount-box">
                        <span>{{ __('pay.binance.checkout.amount_due') }}</span>
                        <div><strong id="binance-amount">{{ $expectedAmount }}</strong><b>USDT</b></div>
                        <button type="button" class="secondary-action" id="binance-copy-amount">
                            {{ __('pay.binance.checkout.copy_amount') }}
                        </button>
                    </div>

                    <dl class="binance-order-summary">
                        <div><dt>{{ __('pay.binance.checkout.order_sn') }}</dt><dd class="mono">{{ $order->order_sn }}</dd></div>
                        <div><dt>{{ __('pay.binance.checkout.cny_amount') }}</dt><dd>¥{{ $order->actual_price }}</dd></div>
                        <div><dt>{{ __('pay.binance.checkout.rate') }}</dt><dd>1 USDT = ¥{{ $attempt->rate }}</dd></div>
                        @if(!empty($config->receiver_binance_id))
                            <div><dt>{{ __('pay.binance.checkout.receiver') }}</dt><dd>{{ $config->receiver_binance_id }}</dd></div>
                        @endif
                    </dl>

                    <div class="payment-waiting" id="binance-payment-status">
                        <i></i><span>{{ __('pay.binance.checkout.waiting') }}</span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>
@stop

@section('js')
<script>
(function () {
    var expiresAtValue = @json($expiresAt);
    var expiresAt = expiresAtValue ? Date.parse(expiresAtValue) : Date.now();
    if (!isFinite(expiresAt)) expiresAt = Date.now();
    var countdown = document.getElementById('binance-countdown');
    var statusBox = document.getElementById('binance-payment-status');
    var copyButton = document.getElementById('binance-copy-amount');
    var pollIntervalMs = 4000;
    var settlementGraceMs = {{ (int) $settlementGraceSeconds }} * 1000;
    var settlementPollBufferMs = {{ (int) $settlementPollBufferSeconds }} * 1000;
    // A final poll interval is included so a payment made just before expiry
    // can still be observed after the API/poller has caught up.
    var settlementDeadline = expiresAt + settlementGraceMs + settlementPollBufferMs;
    var stopped = false;
    var requestInFlight = false;
    var graceNoticeShown = false;
    var finalCheckStarted = false;

    function setStatus(text, state) {
        statusBox.className = 'payment-waiting ' + (state || '');
        statusBox.querySelector('span').textContent = text;
    }

    function stopAsExpired() {
        stopped = true;
        setStatus(@json(__('pay.binance.checkout.expired')), 'expired');
    }

    function updateCountdown() {
        var remaining = Math.max(0, expiresAt - Date.now());
        var seconds = Math.floor(remaining / 1000);
        var minutes = Math.floor(seconds / 60);
        countdown.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
        if (remaining <= 0 && !stopped && !graceNoticeShown) {
            graceNoticeShown = true;
            setStatus(@json(__('pay.binance.checkout.settlement_checking')), 'checking');
        }
    }

    function checkStatus() {
        if (stopped || requestInFlight) return;
        var isFinalCheck = Date.now() > settlementDeadline;
        if (isFinalCheck && finalCheckStarted) {
            stopAsExpired();
            return;
        }
        if (isFinalCheck) finalCheckStarted = true;
        requestInFlight = true;
        fetch(@json($statusUrl), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data) return;
                if (data.paid) {
                    stopped = true;
                    setStatus(@json(__('pay.binance.checkout.paid')), 'paid');
                    window.setTimeout(function () {
                        window.location.href = data.redirect_url || @json($returnUrl);
                    }, 900);
                } else if (data.status === 'manual_review') {
                    stopped = true;
                    setStatus(@json(__('pay.binance.checkout.manual_review')), 'expired');
                } else if (data.status === 'expired' || data.status === 'failed') {
                    if (data.status === 'failed') {
                        stopped = true;
                        setStatus(@json(__('pay.binance.checkout.failed')), 'expired');
                    } else if (Date.now() > settlementDeadline) {
                        stopAsExpired();
                    } else {
                        setStatus(@json(__('pay.binance.checkout.settlement_checking')), 'checking');
                    }
                }
            })
            .catch(function () {})
            .then(function () {
                requestInFlight = false;
                if (stopped) return;
                if (isFinalCheck) {
                    stopAsExpired();
                } else {
                    window.setTimeout(checkStatus, pollIntervalMs);
                }
            });
    }

    function fallbackCopy(text) {
        var input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.top = '0';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.focus();
        input.select();
        var copied = false;
        try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
        document.body.removeChild(input);
        return copied;
    }

    function showCopied() {
        copyButton.textContent = @json(__('pay.binance.checkout.copied'));
        window.setTimeout(function () { copyButton.textContent = @json(__('pay.binance.checkout.copy_amount')); }, 1600);
    }

    if (copyButton) copyButton.addEventListener('click', function () {
        var amount = document.getElementById('binance-amount').textContent.trim();
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(amount).then(showCopied).catch(function () {
                if (fallbackCopy(amount)) showCopied();
            });
        } else if (fallbackCopy(amount)) {
            showCopied();
        }
    });

    updateCountdown();
    window.setInterval(updateCountdown, 1000);
    checkStatus();
})();
</script>
@stop
