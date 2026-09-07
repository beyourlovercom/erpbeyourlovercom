<?php

namespace App\Modules\Finance\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class FxSettingsController extends Controller
{
    public function index(FxReferenceSettingsService $settings): Response
    {
        return Inertia::render('Settings/Fx', $settings->snapshot());
    }

    public function update(Request $request, FxReferenceSettingsService $settings): RedirectResponse
    {
        $rules = ['rates' => ['required', 'array:USD,EUR,GBP,CAD,AUD']];
        foreach (['USD', 'EUR', 'GBP', 'CAD', 'AUD'] as $currency) {
            $rules['rates.'.$currency] = ['nullable', 'numeric', 'gt:0', 'max:100000'];
        }
        $validated = $request->validate($rules);
        $settings->saveManualRates($validated['rates'], $request->user()?->getAuthIdentifier());

        return back()->with('success', '汇率设置已保存。');
    }
}
