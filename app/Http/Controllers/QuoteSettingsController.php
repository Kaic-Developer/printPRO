<?php

namespace App\Http\Controllers;

use App\Actions\ManageQuoteSettings;
use Illuminate\Http\Request;

class QuoteSettingsController extends Controller
{
    public function index(Request $request, ManageQuoteSettings $settings)
    {
        return view('quotes.settings', ['categories' => $settings->catalog($request->user()), 'pricing' => $settings->pricing($request->user())]);
    }

    public function update(Request $request, ManageQuoteSettings $settings)
    {
        $validated = $request->validate([
            'items' => ['sometimes', 'array', 'max:300'],
            'items.*' => ['array'],
            'items.*.is_enabled' => ['sometimes', 'boolean'],
            'items.*.unit_cost' => ['nullable', 'string', 'max:24', 'regex:/\A(?:\d{1,3}(?:\.\d{3}){0,4}|\d{1,15})(?:,\d{1,2}|\.\d{1,2})?\z/'],
            'items.*.material_width_mm' => ['nullable', 'integer', 'between:1,10000'],
            'items.*.material_length_mm' => ['nullable', 'integer', 'between:1,10000'],
            'waste_percentage' => ['nullable', 'string', 'regex:/\A\d{1,7}(?:[.,]\d{1,2})?\z/'],
            'markup_multiplier' => ['nullable', 'string', 'regex:/\A\d{1,7}(?:[.,]\d{1,2})?\z/'],
        ]);
        $settings->update($request->user(), $validated);
        return redirect()->route('quote-settings.index')->with('status', 'Configurações de orçamento atualizadas.');
    }
}
