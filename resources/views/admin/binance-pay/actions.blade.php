<div class="form-group row">
    <div class="col-md-8 offset-md-2">
        <button type="button" class="btn btn-outline-primary" id="binance-pay-test">
            <i class="fa fa-plug"></i> {{ admin_trans('pay.binance.actions.test') }}
        </button>
        <span class="ml-2 text-muted" id="binance-pay-test-result"></span>
        <div class="mt-1 text-muted small">{{ admin_trans('pay.binance.helps.test_saved_credentials') }}</div>
    </div>
</div>
<script>
Dcat.ready(function () {
    var button = $('#binance-pay-test');
    var result = $('#binance-pay-test-result');
    button.on('click', function () {
        if (button.prop('disabled')) return;
        button.prop('disabled', true);
        result.removeClass('text-danger text-success').addClass('text-muted').text(@json(admin_trans('pay.binance.actions.testing')));
        $.ajax({
            url: @json(admin_url('binance-pay/test')),
            method: 'POST',
            data: { _token: @json(csrf_token()) }
        }).done(function (response) {
            result.removeClass('text-muted text-danger').addClass('text-success').text(response.message);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            result.removeClass('text-muted text-success').addClass('text-danger').text(response.message || @json(admin_trans('pay.binance.messages.test_failed')));
        }).always(function () {
            button.prop('disabled', false);
        });
    });
});
</script>
