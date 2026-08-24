<?php

use Illuminate\Support\Facades\Route;

/**
 * Read the owner API reference from the release directory without copying
 * documentation into the public asset tree.
 */
$readApiDocs = function (): string {
    $path = base_path('docs/api.md');

    if (!is_file($path)) {
        abort(404);
    }

    $markdown = file_get_contents($path);
    if ($markdown === false) {
        abort(500);
    }

    return $markdown;
};

$renderApiDocs = function () use ($readApiDocs) {
    $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);

    $html = (string) $converter->convertToHtml($readApiDocs());

    return response()
        ->view('docs.api', ['content' => $html])
        ->header('Content-Type', 'text/html; charset=UTF-8')
        ->header('Cache-Control', 'public, max-age=300')
        ->header('X-Content-Type-Options', 'nosniff')
        ->header(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'"
        );
};

$serveApiDocs = function () use ($readApiDocs) {
    $path = base_path('docs/api.md');
    $readApiDocs();

    return response()->file($path, [
        'Content-Disposition' => 'inline; filename="api.md"',
        'Content-Type' => 'text/markdown; charset=UTF-8',
        'Cache-Control' => 'public, max-age=300',
        'X-Content-Type-Options' => 'nosniff',
    ]);
};

Route::get('docs', function () {
    return redirect('/docs/api');
});
Route::get('docs/api', $renderApiDocs)->name('docs.api');
Route::get('docs/api.md', $serveApiDocs);
