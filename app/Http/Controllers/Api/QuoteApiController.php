<?php

namespace App\Http\Controllers\Api;

use App\Actions\ApproveQuote;
use App\Actions\BuildQuoteVersion;
use App\Actions\ManageQuoteSettings;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\Quote;
use App\Services\RectangleNestingEstimator;
use Illuminate\Http\Request;

class QuoteApiController extends Controller
{
    public function catalog(Request $request, ManageQuoteSettings $settings)
    {
        return response()->json(['categories' => $settings->catalog($request->user()), 'pricing' => $settings->pricing($request->user())]);
    }

    public function updateSettings(Request $request, ManageQuoteSettings $settings)
    {
        $data = $request->validate([
            'items' => ['sometimes', 'array', 'max:300'], 'items.*' => ['array'],
            'items.*.is_enabled' => ['required', 'boolean'],
            'items.*.unit_cost' => ['nullable', 'string', 'max:24', 'regex:/\A(?:\d{1,3}(?:\.\d{3}){0,4}|\d{1,15})(?:,\d{1,2}|\.\d{1,2})?\z/'],
            'waste_percentage' => ['nullable', 'string', 'regex:/\A\d{1,7}(?:[.,]\d{1,2})?\z/'],
            'markup_multiplier' => ['nullable', 'string', 'regex:/\A\d{1,7}(?:[.,]\d{1,2})?\z/'],
        ]);
        $settings->update($request->user(), $data);
        return response()->json(['categories' => $settings->catalog($request->user()), 'pricing' => $settings->pricing($request->user())]);
    }

    public function quotes(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        return response()->json(Quote::query()->where('organization_id', $request->user()->organization_id)->with(['customer', 'versions.items.components'])->orderByDesc('created_at')->paginate($perPage));
    }

    public function store(Request $request, BuildQuoteVersion $builder)
    {
        $quote = $builder->create($request->user(), $this->quoteInput($request))->load('customer', 'versions.items.components');
        return response()->json(['data' => $quote], 201);
    }

    public function show(Request $request, int $quote)
    {
        $record = Quote::query()->where('organization_id', $request->user()->organization_id)->whereKey($quote)->with(['customer', 'versions.items.components', 'productionOrders'])->firstOrFail();
        return response()->json(['data' => $record]);
    }

    public function revise(Request $request, int $quote, BuildQuoteVersion $builder)
    {
        $record = Quote::query()->where('organization_id', $request->user()->organization_id)->findOrFail($quote);
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]) + $this->quoteInput($request);
        $version = $builder->revise($request->user(), $record, $data);
        return response()->json(['data' => $version->load('items.components')], 201);
    }

    public function approve(Request $request, int $quote, ApproveQuote $approval)
    {
        $record = Quote::query()->where('organization_id', $request->user()->organization_id)->findOrFail($quote);
        return response()->json(['data' => $approval->execute($request->user(), $record)]);
    }

    public function productionOrders(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,in_progress,completed,cancelled'], 'sector' => ['nullable', 'string', 'max:48']]);
        $query = ProductionOrder::query()->where('organization_id', $request->user()->organization_id)->with('quote:id,number,status');
        if (isset($data['status'])) $query->where('status', $data['status']);
        if (isset($data['sector'])) $query->where('sector', $data['sector']);
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function nesting(Request $request, RectangleNestingEstimator $estimator)
    {
        $input = $request->validate([
            'material_type' => ['required', 'in:sheet,roll'],
            'piece_width_mm' => ['required', 'integer', 'min:1', 'max:10000'],
            'piece_length_mm' => ['required', 'integer', 'min:1', 'max:10000'],
            'material_width_mm' => ['required', 'integer', 'min:1', 'max:10000'],
            'material_length_mm' => ['required_if:material_type,sheet', 'integer', 'min:1', 'max:10000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'gap_mm' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        return response()->json(['data' => $estimator->estimate($input)]);
    }

    private function quoteInput(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', 'integer'], 'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.preset_code' => ['required', 'string', 'max:96'],
            'items.*.quantity' => ['nullable', 'string', 'max:16', 'regex:/\A\d{1,9}(?:[.,]\d{1,3})?\z/'],
            'items.*.answers' => ['nullable', 'array'],
            'items.*.components' => ['nullable', 'array', 'max:60'],
            'items.*.components.*.code' => ['required', 'string', 'max:96'],
            'items.*.components.*.selected' => ['nullable', 'boolean'],
            'items.*.components.*.quantity' => ['nullable', 'string', 'max:16', 'regex:/\A\d{1,9}(?:[.,]\d{1,3})?\z/'],
        ]);
    }
}
