<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Web\SiteController;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public marketing page (/). Arabic by default, ?lang=en|ar switches and is kept
 * in the same session key as the dashboards so /provider/login follows it.
 */
class LandingController extends SiteController
{
    public function __invoke(Request $request): View
    {
        return $this->render($request, 'landing.index', [
            'plans' => Plan::active()->with('items')->orderByDesc('is_free')->orderBy('sort_order')->orderBy('price')->get(),
        ]);
    }
}
