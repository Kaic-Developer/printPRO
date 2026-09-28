<?php

namespace App\Http\Controllers;

use App\Actions\ApproveQuote;
use App\Actions\BuildQuoteVersion;
use App\Actions\ManageQuoteSettings;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuotePreset;
use App\Services\RectangleNestingEstimator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    public function index(Request $request)
    {
        $quotes = Quote::query()->where('organization_id', $request->user()->organization_id)->with('customer')->orderByDesc('created_at')->paginate(12)->withQueryString();
        $quotes->getCollection()->each(function (Quote $quote): void {
            $quote->setRelation('currentVersionRecord', $quote->versions()->where('version_number', $quote->current_version)->first());
        });
        return view('quotes.index', compact('quotes'));
    }

    public function create(Request $request, ManageQuoteSettings $settings)
    {
        $enabled = $settings->enabledPresetMap($request->user());
        $presets = QuotePreset::query()->where('kind', 'product')->where('is_available', true)->orderBy('name')->get()->filter(fn (QuotePreset $preset) => $enabled[$preset->id] ?? false)->values();
        $components = QuotePreset::query()->whereIn('kind', ['material', 'process', 'finish', 'third_party'])->where('is_available', true)->orderBy('name')->get();
        $costs = DB::table('organization_quote_presets')->where('organization_id', $request->user()->organization_id)->get()->keyBy('quote_preset_id');
        $components->each(function (QuotePreset $component) use ($costs, $enabled): void {
            $configuration = $costs->get($component->id);
            $component->setAttribute('unit_cost_cents', $configuration?->unit_cost_cents);
            $component->setAttribute('tenant_enabled', $enabled[$component->id] ?? false);
            $component->setAttribute('material_width_mm', $configuration?->material_width_mm);
            $component->setAttribute('material_length_mm', $configuration?->material_length_mm);
        });
        $nestingMaterials = $components->filter(fn (QuotePreset $component): bool => $component->kind === 'material'
            && (bool) $component->tenant_enabled
            && $component->material_width_mm !== null
            && in_array($component->unit, ['chapa', 'folha', 'unidade', 'm', 'm²'], true))
            ->filter(fn (QuotePreset $component): bool => in_array($component->unit, ['m', 'm²'], true)
                ? true
                : $component->material_length_mm !== null)
            ->values();
        $customers = Customer::query()->where('organization_id', $request->user()->organization_id)->orderBy('name')->get(['id', 'name']);
        return view('quotes.create', ['customers' => $customers, 'presets' => $presets, 'components' => $components, 'nestingMaterials' => $nestingMaterials, 'pricing' => $settings->pricing($request->user())]);
    }

    public function store(Request $request, BuildQuoteVersion $builder)
    {
        $quote = $builder->create($request->user(), $this->validateQuote($request));
        return redirect()->route('quotes.show', $quote)->with('status', $quote->versions->first()->is_calculable
            ? 'Orçamento salvo e calculado.'
            : 'Rascunho salvo. Configure custos, perda e multiplicador para liberar aprovação.');
    }

    public function show(Request $request, int $quote)
    {
        $record = Quote::query()->where('organization_id', $request->user()->organization_id)->whereKey($quote)->with(['customer', 'versions.items.components', 'productionOrders'])->firstOrFail();
        return view('quotes.show', ['quote' => $record, 'currentVersion' => $record->versions->firstWhere('version_number', $record->current_version)]);
    }

    public function approve(Request $request, int $quote, ApproveQuote $approval)
    {
        $record = Quote::query()->where('organization_id', $request->user()->organization_id)->findOrFail($quote);
        $approval->execute($request->user(), $record);
        return redirect()->route('quotes.show', $record)->with('status', 'Orçamento aprovado e ordens de produção geradas por setor.');
    }

    public function nesting(Request $request, RectangleNestingEstimator $estimator)
    {
        $input = $request->validate([
            'material_type' => ['required', 'in:sheet,roll'], 'piece_width_mm' => ['required', 'integer', 'min:1', 'max:10000'],
            'piece_length_mm' => ['required', 'integer', 'min:1', 'max:10000'], 'material_width_mm' => ['required', 'integer', 'min:1', 'max:10000'],
            'material_length_mm' => ['required_if:material_type,sheet', 'integer', 'min:1', 'max:10000'], 'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'gap_mm' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        return response()->json(['estimate' => $estimator->estimate($input)]);
    }

    public function revise(Request $request, int $quote, BuildQuoteVersion $builder)
    {
        $record = Quote::query()->where('organization_id', $request->user()->organization_id)->findOrFail($quote);
        $version = $builder->revise($request->user(), $record, $this->validateQuote($request));
        return redirect()->route('quotes.show', $record)->with('status', 'Nova versão '.$version->version_number.' criada.');
    }

    private function validateQuote(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', 'integer'], 'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.preset_code' => ['required', 'string', 'max:96'],
            'items.*.quantity' => ['nullable', 'string', 'max:16', 'regex:/\A\d{1,9}(?:[.,]\d{1,3})?\z/'],
            'items.*.answers' => ['nullable', 'array'], 'items.*.components' => ['nullable', 'array', 'max:60'],
            'items.*.components.*.code' => ['required', 'string', 'max:96'], 'items.*.components.*.selected' => ['nullable', 'boolean'],
            'items.*.components.*.quantity' => ['nullable', 'string', 'max:16', 'regex:/\A\d{1,9}(?:[.,]\d{1,3})?\z/'],
            'items.*.nesting' => ['nullable', 'array'],
            'items.*.nesting.material_code' => ['required_with:items.*.nesting', 'string', 'max:96'],
            'items.*.nesting.material_type' => ['required_with:items.*.nesting', 'in:sheet,roll'],
            'items.*.nesting.quantity' => ['required_with:items.*.nesting', 'integer', 'min:1', 'max:100000'],
            'items.*.nesting.piece_width_mm' => ['required_with:items.*.nesting', 'integer', 'min:1', 'max:10000'],
            'items.*.nesting.piece_length_mm' => ['required_with:items.*.nesting', 'integer', 'min:1', 'max:10000'],
            'items.*.nesting.material_width_mm' => ['required_with:items.*.nesting', 'integer', 'min:1', 'max:10000'],
            'items.*.nesting.material_length_mm' => ['required_if:items.*.nesting.material_type,sheet', 'nullable', 'integer', 'min:1', 'max:10000'],
            'items.*.nesting.gap_mm' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
    }
}
