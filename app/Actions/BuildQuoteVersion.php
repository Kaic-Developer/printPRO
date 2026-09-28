<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\OrganizationQuoteSetting;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteItemComponent;
use App\Models\QuotePreset;
use App\Models\QuoteVersion;
use App\Models\User;
use App\Services\QuotePricingCalculator;
use App\Services\QuoteComponentRequirements;
use App\Services\QuoteWizardValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuildQuoteVersion
{
    public function __construct(private QuotePricingCalculator $pricing, private QuoteWizardValidator $wizard, private QuoteComponentRequirements $componentRequirements) {}

    public function create(User $user, array $data): Quote
    {
        return DB::transaction(function () use ($user, $data): Quote {
            $customer = $this->customer($user, $data['customer_id'] ?? null);
            $quote = Quote::create([
                'organization_id' => $user->organization_id,
                'customer_id' => $customer?->id,
                'created_by' => $user->id,
                'number' => 'TMP-'.Str::uuid(),
                'status' => 'draft',
                'current_version' => 1,
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            // O identificador do registro dá um número sem colisão mesmo sob criação concorrente.
            $quote->update(['number' => 'ORC-'.str_pad((string) $quote->id, 8, '0', STR_PAD_LEFT)]);
            $this->build($quote, $user, $data['items'] ?? [], 1);
            return $quote->load('versions.items.components', 'customer', 'productionOrders');
        });
    }

    public function revise(User $user, Quote $quote, array $data): QuoteVersion
    {
        return DB::transaction(function () use ($user, $quote, $data): QuoteVersion {
            $locked = Quote::query()->where('organization_id', $user->organization_id)->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') {
                throw ValidationException::withMessages(['quote' => 'Um orçamento aprovado não pode ser alterado. Crie outro orçamento para uma nova proposta.']);
            }
            if (isset($data['expected_version']) && (int) $data['expected_version'] !== $locked->current_version) {
                throw ValidationException::withMessages(['expected_version' => 'O orçamento foi alterado por outra pessoa. Atualize os dados antes de enviar a revisão.']);
            }
            if (array_key_exists('customer_id', $data)) {
                $locked->customer_id = $this->customer($user, $data['customer_id'])?->id;
            }
            if (array_key_exists('expires_at', $data)) {
                $locked->expires_at = $data['expires_at'];
            }
            $versionNumber = $locked->current_version + 1;
            $this->build($locked, $user, $data['items'] ?? [], $versionNumber);
            $locked->current_version = $versionNumber;
            $locked->save();
            return $locked->versions()->where('version_number', $versionNumber)->with('items.components')->firstOrFail();
        });
    }

    private function build(Quote $quote, User $user, array $items, int $versionNumber): QuoteVersion
    {
        if (count($items) < 1 || count($items) > 100) {
            throw ValidationException::withMessages(['items' => 'Inclua entre 1 e 100 itens no orçamento.']);
        }

        $settings = OrganizationQuoteSetting::query()->where('organization_id', $user->organization_id)->first();
        $presetSettings = DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->get()->keyBy('quote_preset_id');
        $snapshotItems = [];
        $costTotal = 0;
        $costsComplete = true;

        foreach (array_values($items) as $index => $input) {
            $preset = QuotePreset::query()->where('code', $input['preset_code'] ?? '')->where('kind', 'product')->where('is_available', true)->first();
            if (! $preset || ! $this->enabledFor($preset, $user->organization_id, $presetSettings)) {
                throw ValidationException::withMessages(["items.{$index}.preset_code" => 'Selecione um produto ativo da sua gráfica.']);
            }
            $answersInput = $input['answers'] ?? [];
            if (array_key_exists('quantity', $input) && collect($preset->wizard_schema['fields'] ?? [])->contains(fn (array $field) => $field['key'] === 'quantity')) {
                $answersInput['quantity'] = $input['quantity'];
            }
            $answers = $this->wizard->validate($preset->wizard_schema ?? [], $answersInput);
            $quantityMilli = $this->lineQuantity($input, $answers, $index);
            $componentsInput = $input['components'] ?? [];
            if (! is_array($componentsInput)) {
                throw ValidationException::withMessages(["items.{$index}.components" => 'Selecione os insumos e processos aplicáveis.']);
            }

            $suggested = $preset->suggested_components ?? [];
            $selected = collect($componentsInput)->filter(fn ($component) => is_array($component) && filter_var($component['selected'] ?? false, FILTER_VALIDATE_BOOLEAN))->values();
            $codes = $selected->pluck('code')->all();
            if (count($codes) !== count(array_unique($codes)) || count(array_diff($codes, $suggested)) > 0 || (count($codes) === 0 && count($suggested) > 0)) {
                throw ValidationException::withMessages(["items.{$index}.components" => 'Inclua pelo menos um componente sugerido e não repita códigos.']);
            }
            if ($missing = $this->componentRequirements->missing($preset, $answers, $codes)) {
                throw ValidationException::withMessages(["items.{$index}.components" => 'A ficha técnica não inclui todos os componentes exigidos pelas escolhas do wizard: '.implode(', ', $missing).'.']);
            }
            $componentPresets = QuotePreset::query()->whereIn('code', $codes)->get()->keyBy('code');
            $hasSuggestedMaterial = QuotePreset::query()->whereIn('code', $suggested)->where('kind', 'material')->exists();
            $hasSelectedMaterial = $componentPresets->contains(fn (QuotePreset $component) => $component->kind === 'material');
            $hasSuggestedProcess = QuotePreset::query()->whereIn('code', $suggested)->whereIn('kind', ['process', 'finish', 'third_party'])->exists();
            $hasSelectedProcess = $componentPresets->contains(fn (QuotePreset $component) => in_array($component->kind, ['process', 'finish', 'third_party'], true));
            if (($hasSuggestedMaterial && ! $hasSelectedMaterial) || ($hasSuggestedProcess && ! $hasSelectedProcess)) {
                throw ValidationException::withMessages(["items.{$index}.components" => 'Confira pelo menos um material e um processo/acabamento para este produto.']);
            }

            $costCents = 0;
            $itemComplete = true;
            $componentSnapshots = [];
            $item = new QuoteItem([
                'preset_code' => $preset->code,
                'name' => $preset->name,
                'unit' => $preset->unit ?? 'unidade',
                'quantity_milli' => $quantityMilli,
                'answers' => $answers,
                'production_sector' => $preset->production_sector,
            ]);

            foreach ($selected as $componentInput) {
                $code = (string) ($componentInput['code'] ?? '');
                $component = $componentPresets->get($code);
                if (! $component || ! $this->enabledFor($component, $user->organization_id, $presetSettings)) {
                    throw ValidationException::withMessages(["items.{$index}.components" => 'Um dos componentes selecionados foi desativado ou não pertence a este produto.']);
                }
                $quantityRaw = $componentInput['quantity'] ?? null;
                try {
                    $componentQuantity = $this->pricing->quantityMilli(is_string($quantityRaw) || is_int($quantityRaw) ? $quantityRaw : '');
                } catch (Throwable) {
                    throw ValidationException::withMessages(["items.{$index}.components" => "Informe a quantidade de {$component->name} com até três casas decimais."]);
                }
                // O wizard informa consumo por peça; o snapshot e o custo usam o consumo total da linha.
                $totalComponentQuantity = $this->pricing->multiplyMilli($componentQuantity, $quantityMilli);
                $tenantConfig = $component ? $presetSettings->get($component->id) : null;
                $unitCost = $tenantConfig?->unit_cost_cents === null ? null : (int) $tenantConfig->unit_cost_cents;
                try {
                    $componentCost = $unitCost !== null ? $this->pricing->componentCost($unitCost, $totalComponentQuantity) : null;
                } catch (Throwable) {
                    throw ValidationException::withMessages(["items.{$index}.components" => 'O total de um componente ultrapassa o limite de cálculo seguro.']);
                }
                if ($componentCost === null) {
                    $itemComplete = false;
                } else {
                    $costCents = $this->pricing->addCents($costCents, $componentCost);
                }

                $componentSnapshots[] = [
                    'code' => $component->code,
                    'name' => $component->name,
                    'kind' => $component->kind,
                    'unit' => $component->unit ?? 'unidade',
                    'quantity_per_unit_milli' => $componentQuantity,
                    'quantity_milli' => $totalComponentQuantity,
                    'unit_cost_cents' => $unitCost,
                    'cost_cents' => $componentCost,
                    'production_sector' => $component->production_sector,
                ];
            }

            $customOutsourceCents = isset($answers['outsourced_cost_cents']) ? (int) $answers['outsourced_cost_cents'] : 0;
            if ($customOutsourceCents > 0) {
                $costCents = $this->pricing->addCents($costCents, $customOutsourceCents);
                $componentSnapshots[] = ['code' => 'custom-third-party', 'name' => 'Serviço de terceiro informado', 'kind' => 'third_party', 'unit' => 'serviço', 'quantity_per_unit_milli' => null, 'quantity_milli' => 1000, 'unit_cost_cents' => $customOutsourceCents, 'cost_cents' => $customOutsourceCents, 'production_sector' => 'acabamento'];
            }

            $pricingReady = $settings?->waste_basis_points !== null && $settings?->markup_multiplier_basis_points !== null && $settings->markup_multiplier_basis_points > 0;
            $itemSale = null;
            if ($itemComplete && $costCents > 0 && $pricingReady) {
                $calculated = $this->pricing->total($costCents, (int) $settings->waste_basis_points, (int) $settings->markup_multiplier_basis_points);
                $itemSale = $calculated['sale_total_cents'];
            }
            if (count($componentSnapshots) === 0 || $costCents < 1) {
                $itemComplete = false;
                $itemSale = null;
            }
            if (! $itemComplete || ! $pricingReady) {
                $costsComplete = false;
            }
            if ($itemComplete) {
                $costTotal = $this->pricing->addCents($costTotal, $costCents);
            }
            $item->cost_cents = $itemComplete ? $costCents : null;
            $item->sale_cents = $itemSale;
            $snapshotItems[] = [
                'preset_code' => $preset->code,
                'name' => $preset->name,
                'unit' => $preset->unit ?? 'unidade',
                'quantity_milli' => $quantityMilli,
                'answers' => $answers,
                'components' => $componentSnapshots,
                'cost_cents' => $itemComplete ? $costCents : null,
                'sale_cents' => $itemSale,
                'production_sector' => $preset->production_sector,
            ];
            $builtItems[] = ['item' => $item, 'components' => $componentSnapshots];
        }

        $calculationReady = $costsComplete && $settings?->waste_basis_points !== null && $settings?->markup_multiplier_basis_points !== null && $settings->markup_multiplier_basis_points > 0;
        $saleTotal = null;
        if ($calculationReady) {
            $saleTotal = $this->pricing->total($costTotal, (int) $settings->waste_basis_points, (int) $settings->markup_multiplier_basis_points)['sale_total_cents'];
            // O pequeno resíduo de arredondamento global fica na última linha para a soma fechar exatamente.
            $lineTotal = array_sum(array_column($snapshotItems, 'sale_cents'));
            $delta = $saleTotal - $lineTotal;
            $last = array_key_last($snapshotItems);
            $snapshotItems[$last]['sale_cents'] += $delta;
            $builtItems[$last]['item']->sale_cents += $delta;
        }
        $version = QuoteVersion::create([
            'quote_id' => $quote->id,
            'version_number' => $versionNumber,
            'created_by' => $user->id,
            'snapshot' => [
                'currency' => 'BRL',
                'pricing' => [
                    'waste_basis_points' => $settings?->waste_basis_points,
                    'markup_multiplier_basis_points' => $settings?->markup_multiplier_basis_points,
                    'costs_complete' => $costsComplete,
                ],
                'customer' => $quote->customer?->only(['id', 'name']),
                'items' => $snapshotItems,
            ],
            'cost_total_cents' => $costsComplete ? $costTotal : null,
            'sale_total_cents' => $saleTotal,
            'is_calculable' => $calculationReady,
        ]);

        foreach ($builtItems as $built) {
            $item = $built['item'];
            $item->quote_version_id = $version->id;
            $item->save();
            foreach ($built['components'] as $component) {
                QuoteItemComponent::create([
                    'quote_item_id' => $item->id,
                    'preset_code' => $component['code'],
                    'name' => $component['name'],
                    'kind' => $component['kind'],
                    'unit' => $component['unit'],
                    'quantity_per_unit_milli' => $component['quantity_per_unit_milli'],
                    'quantity_milli' => $component['quantity_milli'],
                    'unit_cost_cents' => $component['unit_cost_cents'],
                    'cost_cents' => $component['cost_cents'],
                    'production_sector' => $component['production_sector'],
                ]);
            }
        }

        return $version;
    }

    private function customer(User $user, mixed $customerId): ?Customer
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }
        return Customer::query()->where('organization_id', $user->organization_id)->findOrFail($customerId);
    }

    private function lineQuantity(array $input, array $answers, int $index): int
    {
        $grid = $answers['size_grid'] ?? null;
        if (is_array($grid)) {
            $quantity = array_sum(array_map('intval', $grid));
            return $quantity * 1000;
        }
        $raw = $input['quantity'] ?? $answers['quantity'] ?? null;
        try {
            return $this->pricing->quantityMilli(is_string($raw) || is_int($raw) ? $raw : '');
        } catch (Throwable) {
            throw ValidationException::withMessages(["items.{$index}.quantity" => 'Informe a quantidade do produto.']);
        }
    }

    private function enabledFor(QuotePreset $preset, int $organizationId, $settings): bool
    {
        $current = $preset;
        while ($current !== null) {
            $tenantRow = $settings->get($current->id);
            if ($tenantRow === null || ! (bool) $tenantRow->is_enabled) {
                return false;
            }
            $current = $current->parent_id ? QuotePreset::query()->find($current->parent_id) : null;
        }
        return true;
    }
}
