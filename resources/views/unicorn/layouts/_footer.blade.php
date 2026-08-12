<footer class="store-footer">
    <div class="store-shell store-footer-inner">
        <span>&copy; {{ date('Y') }} {{ dujiaoka_config_get('text_logo') ?: 'NewZoe' }}</span>
        <div>{!! dujiaoka_config_get('footer') !!}</div>
    </div>
</footer>
