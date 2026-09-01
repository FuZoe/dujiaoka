<div class="form-group row">
    <div class="col-md-8 offset-md-2">
        <button type="button" class="btn btn-outline-primary" id="warzone-supplier-test" {{ $goodsId > 0 ? '' : 'disabled' }}>
            <i class="fa fa-plug"></i> {{ admin_trans('warzone-supplier.actions.test') }}
        </button>
        <span class="ml-2 text-muted" id="warzone-supplier-test-result"></span>
        <div class="mt-1 text-muted small">{{ admin_trans('warzone-supplier.helps.test_saved_configuration') }}</div>
    </div>
</div>

<script>
Dcat.ready(function () {
    var button = $('#warzone-supplier-test');
    var result = $('#warzone-supplier-test-result');
    button.on('click', function () {
        if (button.prop('disabled')) return;
        button.prop('disabled', true);
        result.removeClass('text-danger text-success')
            .addClass('text-muted')
            .text(@json(admin_trans('warzone-supplier.actions.testing')));
        $.ajax({
            url: @json(admin_url('warzone-supplier/test')),
            method: 'POST',
            data: {
                _token: @json(csrf_token()),
                goods_id: {{ (int) $goodsId }}
            }
        }).done(function (response) {
            result.removeClass('text-muted text-danger')
                .addClass('text-success')
                .text(response.message);
            window.setTimeout(function () { window.location.reload(); }, 500);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            result.removeClass('text-muted text-success')
                .addClass('text-danger')
                .text(response.message || @json(admin_trans('warzone-supplier.messages.test_failed')));
        }).always(function () {
            button.prop('disabled', false);
        });
    });
});
</script>
