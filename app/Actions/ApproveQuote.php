<?php

namespace App\Actions;

use App\Models\ProductionOrder;
use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApproveQuote
{
    public function execute(User $user, Quote $quote): array
    {
        return DB::transaction(function () use ($user, $quote): array {
            $locked = Quote::query()->where('organization_id', $user->organization_id)->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'sent', 'approved'], true) || ($locked->status !== 'approved' && $locked->expires_at !== null && $locked->expires_at->isBefore(today()))) {
                throw ValidationException::withMessages(['quote' => 'Este orçamento não está em uma situação válida para aprovação ou já expirou.']);
            }
            $version = QuoteVersion::query()->where('quote_id', $locked->id)->where('version_number', $locked->current_version)->with('items.components')->firstOrFail();
            if (! $version->is_calculable || $version->sale_total_cents === null) {
                throw ValidationException::withMessages(['quote' => 'Configure os custos dos componentes, a perda e o multiplicador antes de aprovar este orçamento.']);
            }

            $existing = ProductionOrder::query()->where('quote_version_id', $version->id)->get();
            if ($locked->status !== 'approved') {
                $locked->update(['status' => 'approved', 'approved_at' => now()]);
            }

            if ($existing->isEmpty()) {
                $groups = [];
                foreach ($version->items as $item) {
                    $mainSector = $item->production_sector ?: 'acabamento';
                    $groups[$mainSector]['items'][] = [
                        'name' => $item->name,
                        'quantity_milli' => $item->quantity_milli,
                        'unit' => $item->unit,
                        'answers' => $item->answers,
                        'nesting' => $item->nesting,
                    ];
                    foreach ($item->components as $component) {
                        $sector = $component->production_sector ?: $mainSector;
                        $groups[$sector]['components'][] = [
                            'preset_code' => $component->preset_code,
                            'name' => $component->name,
                            'quantity_milli' => $component->quantity_milli,
                            'quantity_source' => $component->quantity_source,
                            'unit' => $component->unit,
                        ];
                    }
                }

                $now = now();
                $orders = [];
                foreach ($groups as $sector => $snapshot) {
                    $safeSector = Str::slug($sector, '-');
                    $orders[] = [
                        'organization_id' => $user->organization_id,
                        'quote_id' => $locked->id,
                        'quote_version_id' => $version->id,
                        'number' => 'OS-'.$locked->id.'-'.$version->version_number.'-'.$safeSector,
                        'sector' => $sector,
                        'status' => 'pending',
                        'snapshot' => $snapshot,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                // A chave única cobre requisições simultâneas em bancos sem lock de linha efetivo.
                DB::table('production_orders')->insertOrIgnore(array_map(
                    fn (array $order): array => [...$order, 'snapshot' => json_encode($order['snapshot'], JSON_THROW_ON_ERROR)],
                    $orders,
                ));
            }

            return ProductionOrder::query()->where('quote_version_id', $version->id)->orderBy('sector')->get()->all();
        });
    }
}
