<?php

namespace App\Actions;

use App\Models\OrganizationQuoteSetting;
use App\Models\QuotePreset;
use App\Models\User;
use App\Services\QuotePricingCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageQuoteSettings
{
    public function __construct(private QuotePricingCalculator $calculator) {}

    public function catalog(User $user): array
    {
        $tenantRows = DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->get()->keyBy('quote_preset_id');
        $all = QuotePreset::query()->orderBy('name')->get()->keyBy('id');
        $roots = $all->where('kind', 'category')->whereNull('parent_id')->values();
        return $roots->map(function (QuotePreset $root) use ($all, $tenantRows): array {
            return [
                'id' => $root->id,
                'code' => $root->code,
                'name' => $root->name,
                'is_enabled' => (bool) ($tenantRows->get($root->id)?->is_enabled ?? false),
                'items' => $all->where('id', '!=', $root->id)->filter(fn (QuotePreset $preset) => $this->belongsTo($preset, $root, $all))->map(function (QuotePreset $preset) use ($tenantRows, $all, $root): array {
                    $row = $tenantRows->get($preset->id);
                    $path = $this->groupName($preset, $root, $all);
                    return [
                        'id' => $preset->id,
                        'code' => $preset->code,
                        'name' => $preset->name,
                        'kind' => $preset->kind,
                        'unit' => $preset->unit,
                        'group' => $path,
                        'production_sector' => $preset->production_sector,
                        'is_enabled' => (bool) ($row?->is_enabled ?? false),
                        'unit_cost_cents' => $row?->unit_cost_cents === null ? null : (int) $row->unit_cost_cents,
                        'wizard_key' => $preset->wizard_key,
                        'wizard_schema' => $preset->wizard_schema,
                        'suggested_components' => $preset->suggested_components,
                    ];
                })->values()->all(),
            ];
        })->all();
    }

    public function pricing(User $user): ?array
    {
        $settings = OrganizationQuoteSetting::query()->where('organization_id', $user->organization_id)->first();
        if (! $settings || $settings->waste_basis_points === null || $settings->markup_multiplier_basis_points === null) {
            return null;
        }
        return [
            'waste_basis_points' => $settings->waste_basis_points,
            'waste_percentage' => $this->formatBasisPoints($settings->waste_basis_points),
            'markup_multiplier_basis_points' => $settings->markup_multiplier_basis_points,
            'markup_multiplier' => $this->formatBasisPoints($settings->markup_multiplier_basis_points),
        ];
    }

    public function update(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            foreach ($data['items'] ?? [] as $code => $settings) {
                $preset = QuotePreset::query()->where('code', $code)->first();
                if (! $preset) {
                    throw ValidationException::withMessages(['items' => 'O catálogo recebido contém um código inválido.']);
                }
                $enabled = filter_var($settings['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $unitCost = null;
                if (in_array($preset->kind, ['material', 'process', 'finish', 'third_party'], true)) {
                    $rawCost = trim((string) ($settings['unit_cost'] ?? ''));
                    if ($rawCost !== '') {
                        try {
                            $unitCost = $this->calculator->moneyCents($rawCost);
                        } catch (Throwable $exception) {
                            throw ValidationException::withMessages(["items.{$code}.unit_cost" => $exception->getMessage()]);
                        }
                    }
                }
                DB::table('organization_quote_presets')->updateOrInsert(
                    ['organization_id' => $user->organization_id, 'quote_preset_id' => $preset->id],
                    ['is_enabled' => $enabled, 'unit_cost_cents' => $unitCost, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            if (array_key_exists('waste_percentage', $data) || array_key_exists('markup_multiplier', $data)) {
                $pricing = OrganizationQuoteSetting::query()->firstOrNew(['organization_id' => $user->organization_id]);
                try {
                    if (array_key_exists('waste_percentage', $data)) {
                        $raw = trim((string) $data['waste_percentage']);
                        $pricing->waste_basis_points = $raw === '' ? null : $this->calculator->percentageBasisPoints($raw, 1_000_000);
                    }
                    if (array_key_exists('markup_multiplier', $data)) {
                        $raw = trim((string) $data['markup_multiplier']);
                        $value = $raw === '' ? null : $this->calculator->scaledBasisPoints($raw, 10_000_000);
                        if ($value !== null && $value < 1) {
                            throw new \InvalidArgumentException('O multiplicador de markup deve ser maior que zero.');
                        }
                        $pricing->markup_multiplier_basis_points = $value;
                    }
                } catch (Throwable $exception) {
                    throw ValidationException::withMessages(['pricing' => $exception->getMessage()]);
                }
                // Atualizar somente o catálogo preserva fatores comerciais enviados em formulário separado.
                $pricing->organization_id = $user->organization_id;
                $pricing->save();
            }
        });
    }

    public function enabledPresetMap(User $user): array
    {
        $settings = DB::table('organization_quote_presets')->where('organization_id', $user->organization_id)->get()->keyBy('quote_preset_id');
        $presets = QuotePreset::query()->get()->keyBy('id');
        $result = [];
        foreach ($presets as $preset) {
            $current = $preset;
            $enabled = true;
            while ($current !== null) {
                if (! (bool) ($settings->get($current->id)?->is_enabled ?? false)) {
                    $enabled = false;
                    break;
                }
                $current = $current->parent_id ? $presets->get($current->parent_id) : null;
            }
            $result[$preset->id] = $enabled;
        }
        return $result;
    }

    private function belongsTo(QuotePreset $preset, QuotePreset $root, $all): bool
    {
        $current = $preset;
        while ($current->parent_id !== null) {
            if ($current->parent_id === $root->id) {
                return true;
            }
            $current = $all->get($current->parent_id);
            if ($current === null) {
                return false;
            }
        }
        return false;
    }

    private function groupName(QuotePreset $preset, QuotePreset $root, $all): string
    {
        $current = $preset;
        while ($current->parent_id !== null && $current->parent_id !== $root->id) {
            $current = $all->get($current->parent_id);
            if ($current === null) {
                break;
            }
        }
        return $current?->id === $root->id ? $root->name : ($current?->name ?? $root->name);
    }

    private function formatBasisPoints(int $basisPoints): string
    {
        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }
}
