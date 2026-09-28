<?php

namespace App\Actions;

use App\Models\Organization;
use App\Models\QuotePreset;
use Illuminate\Support\Facades\DB;

class InitializeQuoteCatalog
{
    public function execute(?Organization $organization = null): void
    {
        DB::transaction(function () use ($organization): void {
            // Pais aparecem antes dos filhos no catálogo-base; os códigos estáveis preservam customizações já existentes.
            if ($organization === null || ! QuotePreset::query()->exists()) {
                foreach (require database_path('seeders/data/quote-preset-catalog.php') as $row) {
                    $parentId = isset($row['parent_code']) ? QuotePreset::query()->where('code', $row['parent_code'])->value('id') : null;
                    QuotePreset::query()->updateOrCreate(['code' => $row['code']], [
                        'parent_id' => $parentId,
                        'kind' => $row['kind'],
                        'name' => $row['name'],
                        'unit' => $row['unit'] ?? null,
                        'production_sector' => $row['production_sector'] ?? null,
                        'wizard_key' => $row['wizard_key'] ?? null,
                        'suggested_components' => $row['suggested_components'] ?? [],
                        'wizard_schema' => $row['wizard_schema'] ?? null,
                    ]);
                }
            }

            if ($organization !== null) {
                $now = now();
                $rows = QuotePreset::query()->pluck('id')->map(fn (int $id) => [
                    'organization_id' => $organization->id,
                    'quote_preset_id' => $id,
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                // insertOrIgnore mantém os custos e escolhas de ativação já salvos pela gráfica.
                DB::table('organization_quote_presets')->insertOrIgnore($rows);
                DB::table('organization_quote_settings')->insertOrIgnore([
                    'organization_id' => $organization->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
