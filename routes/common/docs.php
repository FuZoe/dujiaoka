<?php

use Illuminate\Support\Facades\Route;

/**
 * Serve the owner API reference from the release directory without copying
 * documentation into the public asset tree.
 */
$serveApiDocs = function () {
    $path = base_path('docs/api.md');

    if (!is_file($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Disposition' => 'inline; filename="api.md"',
        'Content-Type' => 'text/markdown; charset=UTF-8',
        'Cache-Control' => 'public, max-age=300',
        'X-Content-Type-Options' => 'nosniff',
    ]);
};

Route::get('docs/api', $serveApiDocs);
Route::get('docs/api.md', $serveApiDocs);
