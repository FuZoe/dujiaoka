<?php

namespace App\Http\Middleware;

use Closure;

class SetShopLocale
{
    /**
     * Set the locale selected by a storefront route before DujiaoBoot loads
     * the rest of the request configuration.
     */
    public function handle($request, Closure $next, string $locale = 'zh_CN')
    {
        // Payment pages keep their canonical /pay/* URL. The storefront
        // carries the selected language as a query parameter when entering
        // that route, so callbacks and provider integrations remain stable.
        if ($locale === 'zh_CN' && $request->query('locale') === 'en') {
            $locale = 'en';
        }
        $locale = $locale === 'en' ? 'en' : 'zh_CN';
        $request->attributes->set('shop_locale', $locale);
        app()->setLocale($locale);

        return $next($request);
    }
}
