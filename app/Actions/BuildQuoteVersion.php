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
use App\Services\RectangleNestingEstimator;
use App\Services\QuoteWizardValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuildQuoteVersion
{
    public function __construct(private QuotePricingCalculator $pricing, private QuoteWizardValidator $wizard, private QuoteComponentRequirements $componentRequirements, private RectangleNestingEstimator $nestingEstimator) {}

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
            // Produtos cobrados por área devem reconciliar sempre com as dimensões e cópias,
            // mesmo quando o operador escolhe informar o consumo de mídia manualmente.
            $areaCopies = $this->assertCommercialAreaQuantity($preset, $answers, $quantityMilli, $index);
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
            if ($conflicting = $this->componentRequirements->conflicting($preset, $answers, $codes)) {
                throw ValidationException::withMessages(["items.{$index}.components" => 'Remova os componentes incompatíveis com a opção escolhida no assistente: '.implode(', ', $conflicting).'.']);
            }
            $componentPresets = QuotePreset::query()->whereIn('code', $codes)->get()->keyBy('code');
            $hasSuggestedMaterial = QuotePreset::query()->whereIn('code', $suggested)->where('kind', 'material')->exists();
            $hasSelectedMaterial = $componentPresets->contains(fn (QuotePreset $component) => $component->kind === 'material');
            $hasSuggestedProcess = QuotePreset::query()->whereIn('code', $suggested)->whereIn('kind', ['process', 'finish', 'third_party'])->exists();
            $hasSelectedProcess = $componentPresets->contains(fn (QuotePreset $component) => in_array($component->kind, ['process', 'finish', 'third_party'], true));
            if (($hasSuggestedMaterial && ! $hasSelectedMaterial) || ($hasSuggestedProcess && ! $hasSelectedProcess)) {
                throw ValidationException::withMessages(["items.{$index}.components" => 'Confira pelo menos um material e um processo/acabamento para este produto.']);
            }

            $nesting = isset($input['nesting'])
                ? $this->nestingSnapshot($preset, $answers, $quantityMilli, $input['nesting'], $codes, $user->organization_id, $presetSettings, $index, $areaCopies)
                : null;
            $wizardQuantities = $this->wizardCalculatedQuantities($preset, $answers, $quantityMilli, $index);

            $costCents = 0;
            $itemComplete = true;
            $componentSnapshots = [];
            $item = new QuoteItem([
                'preset_code' => $preset->code,
                'name' => $preset->name,
                'unit' => $preset->unit ?? 'unidade',
                'quantity_milli' => $quantityMilli,
                'answers' => $answers,
                'nesting' => $nesting,
                'production_sector' => $preset->production_sector,
            ]);

            foreach ($selected as $componentInput) {
                $code = (string) ($componentInput['code'] ?? '');
                $component = $componentPresets->get($code);
                if (! $component || ! $this->enabledFor($component, $user->organization_id, $presetSettings)) {
                    throw ValidationException::withMessages(["items.{$index}.components" => 'Um dos componentes selecionados foi desativado ou não pertence a este produto.']);
                }
                $componentNesting = $nesting !== null && $component->code === $nesting['material_code'] ? $nesting : null;
                if ($componentNesting !== null) {
                    // O nesting já retorna o consumo total arredondado para cima; não multiplique novamente pela quantidade comercial.
                    $componentQuantity = null;
                    $totalComponentQuantity = $componentNesting['consumed_quantity_milli'];
                } elseif (array_key_exists($component->code, $wizardQuantities)) {
                    $wizardQuantity = $wizardQuantities[$component->code];
                    $componentQuantity = $wizardQuantity['quantity_per_unit_milli'];
                    $totalComponentQuantity = $wizardQuantity['quantity_milli'];
                } else {
                    $quantityRaw = $componentInput['quantity'] ?? null;
                    try {
                        $componentQuantity = $this->pricing->quantityMilli(is_string($quantityRaw) || is_int($quantityRaw) ? $quantityRaw : '');
                    } catch (Throwable) {
                        throw ValidationException::withMessages(["items.{$index}.components" => "Informe a quantidade de {$component->name} com até três casas decimais."]);
                    }
                    // Componentes não calculados por nesting mantêm o consumo por unidade configurado.
                    $totalComponentQuantity = $this->pricing->multiplyMilli($componentQuantity, $quantityMilli);
                }
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
                    'quantity_source' => $componentNesting !== null ? 'nesting' : (array_key_exists($component->code, $wizardQuantities) ? 'wizard' : 'manual'),
                    'unit_cost_cents' => $unitCost,
                    'cost_cents' => $componentCost,
                    'production_sector' => $component->production_sector,
                    'nesting' => $componentNesting,
                ];
            }

            $customOutsourceCents = isset($answers['outsourced_cost_cents']) ? (int) $answers['outsourced_cost_cents'] : 0;
            if ($customOutsourceCents > 0) {
                $costCents = $this->pricing->addCents($costCents, $customOutsourceCents);
                $componentSnapshots[] = ['code' => 'custom-third-party', 'name' => 'Serviço de terceiro informado', 'kind' => 'third_party', 'unit' => 'serviço', 'quantity_per_unit_milli' => null, 'quantity_milli' => 1000, 'quantity_source' => 'manual', 'unit_cost_cents' => $customOutsourceCents, 'cost_cents' => $customOutsourceCents, 'production_sector' => 'acabamento'];
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
                'nesting' => $nesting,
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
                    'quantity_source' => $component['quantity_source'] ?? 'manual',
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

    /** Calcula a área vendável por peça quando o wizard recebe dimensões explícitas da estampa. */
    private function wizardCalculatedQuantities(QuotePreset $product, array $answers, int $lineQuantityMilli, int $index): array
    {
        $personalization = $answers['personalization'] ?? null;
        if ($product->code === 'product-roll-up') {
            // A área da impressão usa as dimensões acabadas; o nesting calcula separadamente a sobra da bobina.
            $width = $this->millimeterInteger($answers['width_mm'] ?? null);
            $height = $this->millimeterInteger($answers['height_mm'] ?? null);
            if ($width === null || $height === null || $width < 1 || $height < 1 || $width > 10_000 || $height > 10_000) {
                throw ValidationException::withMessages(["items.{$index}.answers.width_mm" => 'Informe largura e altura inteiras em milímetros para calcular a impressão do roll-up.']);
            }
            $areaMilliPerPiece = intdiv(($width * $height) + 999, 1000);
            $quantities = ['process-large-format-print' => [
                'quantity_per_unit_milli' => $areaMilliPerPiece,
                'quantity_milli' => $this->pricing->multiplyMilli($areaMilliPerPiece, $lineQuantityMilli),
            ]];
            if (filter_var($answers['stand_included'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                // Cada roll-up entregue com estrutura consome exatamente uma unidade do suporte.
                $quantities['material-roll-up-stand'] = [
                    'quantity_per_unit_milli' => 1000,
                    'quantity_milli' => $lineQuantityMilli,
                ];
            }
            return $quantities;
        }

        if (in_array($product->code, ['product-long-drink-cup', 'product-squeeze', 'product-lanyard', 'product-eco-gift'], true)) {
            // Bases e personalização são cobradas por peça; material e técnica livres ficam descritivos no snapshot.
            $materialCode = match ($product->code) {
                'product-long-drink-cup' => 'material-gift-long-drink-cup',
                'product-squeeze' => 'material-gift-squeeze',
                'product-lanyard' => 'material-gift-lanyard',
                'product-eco-gift' => 'material-eco-gift-base',
            };
            $perPiece = ['quantity_per_unit_milli' => 1000, 'quantity_milli' => $lineQuantityMilli];
            return [$materialCode => $perPiece, 'process-gift-printing' => $perPiece];
        }

        if ($product->code === 'product-dtf-dtg-print') {
            $codes = match ($answers['technique'] ?? null) {
                'dtf' => ['process-dtf-print-size', 'material-textile-dtf-transfer'],
                'dtg' => ['process-dtg-print-size'],
                default => [],
            };
            return $this->printAreaQuantities($answers, $codes, $lineQuantityMilli, 1, $index, 'custom_width_cm', 'custom_height_cm');
        }

        if (in_array($product->code, ['uniform-polo', 'product-sweatshirt', 'product-apron'], true) && $personalization === 'dtf') {
            $codes = $product->code === 'uniform-polo'
                ? ['process-dtf-print-size', 'material-textile-dtf-transfer']
                : ['process-dtf', 'material-textile-dtf-transfer'];
            return $this->printAreaQuantities($answers, $codes, $lineQuantityMilli, 1, $index, 'print_width_cm', 'print_height_cm', 'prints_per_piece');
        }

        if (in_array($product->code, ['uniform-polo', 'product-basic-tshirt', 'product-workwear', 'product-sweatshirt', 'product-apron', 'product-cap'], true)) {
            if ($personalization === 'silk-screen') {
                $colorCount = (int) ($answers['silk_front_colors'] ?? 0) + (int) ($answers['silk_back_colors'] ?? 0);
                return [
                    // Telas e fotolitos são matrizes da linha de produto: cada cor por face é produzida uma vez.
                    'material-silk-screen-screen' => ['quantity_per_unit_milli' => null, 'quantity_milli' => $colorCount * 1000],
                    'material-silk-screen-film' => ['quantity_per_unit_milli' => null, 'quantity_milli' => $colorCount * 1000],
                    // Mão de obra e tinta são calculadas por aplicação de uma cor em uma peça.
                    'process-silk-screen' => ['quantity_per_unit_milli' => $colorCount * 1000, 'quantity_milli' => $this->pricing->multiplyMilli($colorCount * 1000, $lineQuantityMilli)],
                    'material-silk-screen-ink' => ['quantity_per_unit_milli' => $colorCount * 1000, 'quantity_milli' => $this->pricing->multiplyMilli($colorCount * 1000, $lineQuantityMilli)],
                ];
            }
            if ($personalization === 'embroidery') {
                $stitches = (int) ($answers['estimated_stitches'] ?? 0);
                $quantities = [
                    // O processo é precificado por mil pontos; o inteiro de pontos já representa seus milésimos.
                    'process-computerized-embroidery' => ['quantity_per_unit_milli' => $stitches, 'quantity_milli' => $this->pricing->multiplyMilli($stitches, $lineQuantityMilli)],
                ];
                if (filter_var($answers['embroidery_matrix'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    // A matriz é um serviço de preparação por linha/arte, cobrado uma única vez.
                    $quantities['third-party-embroidery-matrix'] = ['quantity_per_unit_milli' => null, 'quantity_milli' => 1000];
                }
                return $quantities;
            }
        }

        if ($product->code !== 'product-basic-tshirt' || ! in_array($personalization, ['dtf', 'dtg'], true)) {
            return [];
        }

        $size = $answers['print_size'] ?? null;
        $standardSizes = [
            'a4' => [210, 297],
            'a3' => [297, 420],
            'a2' => [420, 594],
        ];
        $dimensions = $standardSizes[$size] ?? null;
        $width = $this->millimeterInteger($answers['print_width_mm'] ?? null);
        $height = $this->millimeterInteger($answers['print_height_mm'] ?? null);
        if ($width === null || $height === null || $width < 1 || $height < 1) {
            throw ValidationException::withMessages(["items.{$index}.answers.print_width_mm" => 'Informe dimensões inteiras e positivas para a estampa.']);
        }
        if ($dimensions !== null && !(($width === $dimensions[0] && $height === $dimensions[1]) || ($width === $dimensions[1] && $height === $dimensions[0]))) {
            throw ValidationException::withMessages(["items.{$index}.answers.print_width_mm" => 'As dimensões da estampa devem corresponder ao formato padronizado escolhido.']);
        }
        if ($size !== 'custom_area' && $dimensions === null) {
            throw ValidationException::withMessages(["items.{$index}.answers.print_size" => 'Selecione um tamanho padronizado ou uma área personalizada.']);
        }

        $positions = filter_var($answers['prints_per_piece'] ?? null, FILTER_VALIDATE_INT);
        if ($positions === false || $positions < 1 || $positions > 100) {
            throw ValidationException::withMessages(["items.{$index}.answers.prints_per_piece" => 'Informe de 1 a 100 estampas por peça.']);
        }

        // Milésimos de m² são arredondados para cima para não subestimar material e processo.
        $areaMilliPerPiece = intdiv(($width * $height) + 999, 1000) * $positions;
        $codes = $answers['personalization'] === 'dtf'
            ? ['process-dtf-print-size', 'material-textile-dtf-transfer']
            : ['process-dtg-print-size'];

        $totalAreaMilli = $this->pricing->multiplyMilli($areaMilliPerPiece, $lineQuantityMilli);
        return array_fill_keys($codes, ['quantity_per_unit_milli' => $areaMilliPerPiece, 'quantity_milli' => $totalAreaMilli]);
    }

    /** Calcula área dos formatos têxteis e aplica quantidades sem converter valores em float. */
    private function printAreaQuantities(array $answers, array $codes, int $lineQuantityMilli, int $defaultPositions, int $index, string $customWidthKey, string $customHeightKey, ?string $positionsKey = null): array
    {
        if ($codes === []) return [];

        $standardSizes = ['a4' => [210, 297], 'a3' => [297, 420], 'a2' => [420, 594]];
        $dimensions = $standardSizes[$answers['print_size'] ?? ''] ?? null;
        if ($dimensions === null && ($answers['print_size'] ?? null) === 'custom_area') {
            $widthMilliCm = $this->centimeterMilli($answers[$customWidthKey] ?? null);
            $heightMilliCm = $this->centimeterMilli($answers[$customHeightKey] ?? null);
            if ($widthMilliCm === null || $heightMilliCm === null || $widthMilliCm < 100 || $heightMilliCm < 100) {
                throw ValidationException::withMessages(["items.{$index}.answers.{$customWidthKey}" => 'Informe largura e altura válidas para a área personalizada.']);
            }
            // cm escalados por mil produzem milésimos de m² ao dividir pela conversão exata.
            $areaMilliPerPosition = intdiv(($widthMilliCm * $heightMilliCm) + 9_999_999, 10_000_000);
        } elseif ($dimensions !== null) {
            $areaMilliPerPosition = intdiv(($dimensions[0] * $dimensions[1]) + 999, 1000);
        } else {
            throw ValidationException::withMessages(["items.{$index}.answers.print_size" => 'Selecione A4, A3, A2 ou informe uma área personalizada.']);
        }

        $positions = $positionsKey === null ? $defaultPositions : filter_var($answers[$positionsKey] ?? null, FILTER_VALIDATE_INT);
        if ($positions === false || $positions < 1 || $positions > 100) {
            throw ValidationException::withMessages(["items.{$index}.answers.{$positionsKey}" => 'Informe de 1 a 100 estampas por peça.']);
        }

        // Arredondar cada posição para cima evita subestimar insumos comprados por m².
        $areaMilliPerPiece = $areaMilliPerPosition * $positions;
        $totalAreaMilli = $this->pricing->multiplyMilli($areaMilliPerPiece, $lineQuantityMilli);

        return array_fill_keys($codes, ['quantity_per_unit_milli' => $areaMilliPerPiece, 'quantity_milli' => $totalAreaMilli]);
    }

    private function centimeterMilli(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) return null;
        $value = (string) $value;
        if (! preg_match('/\A(\d{1,4})(?:[.,](\d{1,3}))?\z/', $value, $parts)) return null;
        return (int) $parts[1] * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
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

    /** Confirma o material ativo e prova que a quantidade vendida corresponde às peças encaixadas. */
    private function nestingSnapshot(QuotePreset $product, array $answers, int $lineQuantityMilli, mixed $nesting, array $selectedCodes, int $organizationId, $settings, int $index, ?int $areaCopies): array
    {
        $path = "items.{$index}.nesting";
        if (! is_array($nesting)) {
            throw ValidationException::withMessages([$path => 'Informe os dados de aproveitamento do material.']);
        }
        $materialCode = $nesting['material_code'] ?? null;
        $materialType = $nesting['material_type'] ?? null;
        $quantity = $nesting['quantity'] ?? null;
        if (! is_string($materialCode) || ! in_array($materialCode, $product->suggested_components ?? [], true) || ! in_array($materialCode, $selectedCodes, true)) {
            throw ValidationException::withMessages(["{$path}.material_code" => 'O material precisa estar sugerido e selecionado para este produto.']);
        }
        $expectedMaterial = $this->expectedNestingMaterial($product, $answers, $path);
        if ($materialCode !== $expectedMaterial) {
            throw ValidationException::withMessages(["{$path}.material_code" => 'O material do nesting não corresponde ao material escolhido na ficha do produto.']);
        }
        $expectedMaterialType = $product->code === 'product-labels-roll-sheet'
            ? match ($answers['format'] ?? null) { 'roll' => 'roll', 'sheet' => 'sheet', default => null }
            : null;
        if ($expectedMaterialType !== null && $materialType !== $expectedMaterialType) {
            throw ValidationException::withMessages(["{$path}.material_type" => 'O tipo de nesting deve corresponder à apresentação em bobina ou cartela escolhida no produto.']);
        }
        if (! is_int($quantity) && !(is_string($quantity) && preg_match('/\A\d{1,6}\z/', $quantity))) {
            throw ValidationException::withMessages(["{$path}.quantity" => 'Informe um número inteiro de peças para o nesting.']);
        }
        $quantity = (int) $quantity;
        if ($quantity < 1 || $quantity > 100_000) {
            throw ValidationException::withMessages(["{$path}.quantity" => 'A quantidade de peças deve ser um inteiro entre 1 e 100000.']);
        }

        $material = QuotePreset::query()->where('code', $materialCode)->where('kind', 'material')->where('is_available', true)->first();
        if (! $material || ! $this->enabledFor($material, $organizationId, $settings)) {
            throw ValidationException::withMessages(["{$path}.material_code" => 'O material está inativo ou indisponível para esta gráfica.']);
        }
        $materialSettings = $settings->get($material->id);
        $registeredWidth = (int) ($materialSettings?->material_width_mm ?? 0);
        $registeredLength = (int) ($materialSettings?->material_length_mm ?? 0);
        if ($registeredWidth < 1 || ($materialType === 'sheet' && $registeredLength < 1)) {
            throw ValidationException::withMessages(["{$path}.material_width_mm" => 'Cadastre as dimensões reais deste material nas configurações do catálogo antes de estimar o aproveitamento.']);
        }
        if (($nesting['material_width_mm'] ?? null) != $registeredWidth
            || ($materialType === 'sheet' && ($nesting['material_length_mm'] ?? null) != $registeredLength)) {
            throw ValidationException::withMessages(["{$path}.material_width_mm" => 'As dimensões do nesting devem corresponder às dimensões cadastradas pela gráfica para este material.']);
        }
        if (in_array($product->code, ['product-folder-print', 'product-presentation-folder'], true) && $materialType === 'sheet') {
            $format = $answers['sheet_format'] ?? null;
            // A3/SRA3 descrevem medidas físicas da folha; formato personalizado usa o perfil real do estoque cadastrado pela gráfica.
            $standardDimensions = match ($format) {
                'a3' => [297, 420],
                'sra3' => [320, 450],
                'custom' => null,
                default => false,
            };
            if ($standardDimensions === false
                || ($standardDimensions !== null && ! (
                    ($registeredWidth === $standardDimensions[0] && $registeredLength === $standardDimensions[1])
                    || ($registeredWidth === $standardDimensions[1] && $registeredLength === $standardDimensions[0])
                ))) {
                throw ValidationException::withMessages(["items.{$index}.answers.sheet_format" => 'O formato escolhido não corresponde às dimensões cadastradas para este papel. Escolha Outro formato ou ajuste o estoque no catálogo.']);
            }
        }
        $unit = $material->unit ?? '';
        $compatible = match ($materialType) {
            'sheet' => in_array($unit, ['chapa', 'folha', 'unidade', 'm²'], true),
            'roll' => in_array($unit, ['m', 'm²'], true),
            default => false,
        };
        if (! $compatible) {
            throw ValidationException::withMessages(["{$path}.material_type" => 'A unidade do material não é compatível com chapa, bobina ou área.']);
        }

        $this->assertLineMatchesNesting($product, $answers, $lineQuantityMilli, $quantity, $nesting, $path, $areaCopies);
        try {
            $normalized = [
                'material_type' => $materialType,
                'quantity' => $quantity,
                'piece_width_mm' => $this->nestingInteger($nesting, 'piece_width_mm', $path),
                'piece_length_mm' => $this->nestingInteger($nesting, 'piece_length_mm', $path),
                'material_width_mm' => $this->nestingInteger($nesting, 'material_width_mm', $path),
                'gap_mm' => $this->nestingInteger($nesting, 'gap_mm', $path, 0),
            ];
            if ($materialType === 'sheet') {
                $normalized['material_length_mm'] = $this->nestingInteger($nesting, 'material_length_mm', $path);
            }
            $estimate = $this->nestingEstimator->estimate($normalized);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$path => $exception->getMessage()]);
        }
        if (! $estimate['fits']) {
            throw ValidationException::withMessages([$path => 'A peça não cabe no material informado, mesmo após girar a orientação.']);
        }

        // mm equivale a milésimos de metro; converter área de mm² para milésimos de m² exige arredondar para cima.
        $consumedMilli = match ($unit) {
            'chapa', 'folha', 'unidade' => (int) $estimate['sheets_required'] * 1000,
            'm' => (int) $estimate['roll_length_mm'],
            'm²' => intdiv((int) $estimate['consumed_area_mm2'] + 999, 1000),
            default => 0,
        };
        $estimate['consumed_quantity_milli'] = $consumedMilli;
        $estimate['consumed_unit'] = $unit;

        return [
            'material_code' => $material->code,
            'estimation_basis' => 'operator_confirmed_dimensions_and_rectangular_grid_estimate',
            'quantity' => $quantity,
            'material_type' => $materialType,
            'piece_width_mm' => $normalized['piece_width_mm'],
            'piece_length_mm' => $normalized['piece_length_mm'],
            'material_width_mm' => $normalized['material_width_mm'],
            'material_length_mm' => $materialType === 'sheet' ? $normalized['material_length_mm'] : null,
            'gap_mm' => $normalized['gap_mm'],
            ...$estimate,
        ];
    }

    private function assertLineMatchesNesting(QuotePreset $product, array $answers, int $lineQuantityMilli, int $nestingQuantity, array $nesting, string $path, ?int $areaCopies): void
    {
        $grid = $answers['size_grid'] ?? null;
        if (is_array($grid)) {
            $selectedSizes = array_filter($grid, fn ($count): bool => is_numeric($count) && (int) $count > 0);
            if (count($selectedSizes) > 1) {
                throw ValidationException::withMessages(["{$path}.quantity" => 'Separe os tamanhos em itens distintos antes de calcular nesting; uma única geometria não representa uma grade mista.']);
            }
        }
        if (is_array($grid)) {
            throw ValidationException::withMessages(["{$path}.quantity" => 'Este produto possui grade de tamanhos; divida a proposta por tamanho antes de usar nesting.']);
        }
        $dimensions = $this->answerDimensionsMm($product, $answers);
        if ($dimensions === null) {
            throw ValidationException::withMessages(["{$path}.piece_width_mm" => 'Este produto não tem dimensões físicas confiáveis no wizard; use a estimativa standalone.']);
        }
        // A comparação final inclui a possibilidade de girar a peça na chapa/bobina.
        $nestingWidth = $nesting['piece_width_mm'] ?? null;
        $nestingLength = $nesting['piece_length_mm'] ?? null;
        if ((! is_int($nestingWidth) && !(is_string($nestingWidth) && ctype_digit($nestingWidth))) || (! is_int($nestingLength) && !(is_string($nestingLength) && ctype_digit($nestingLength)))) {
            throw ValidationException::withMessages(["{$path}.piece_width_mm" => 'As dimensões do nesting devem ser inteiras em milímetros.']);
        }
        $nestingWidth = (int) $nestingWidth;
        $nestingLength = (int) $nestingLength;
        if (!(($dimensions[0] === $nestingWidth && $dimensions[1] === $nestingLength) || ($dimensions[0] === $nestingLength && $dimensions[1] === $nestingWidth))) {
            throw ValidationException::withMessages(["{$path}.piece_width_mm" => 'As dimensões do nesting precisam coincidir com as dimensões respondidas no produto, aceitando rotação.']);
        }

        if ($product->unit === 'm²') {
            if ($areaCopies !== $nestingQuantity) {
                throw ValidationException::withMessages(["{$path}.quantity" => 'A quantidade do item em m² deve corresponder às dimensões × cópias informadas no nesting.']);
            }
            return;
        }

        if (! in_array($product->unit, ['unidade', 'peça', 'aplicação', 'bloco'], true) || $lineQuantityMilli % 1000 !== 0 || intdiv($lineQuantityMilli, 1000) !== $nestingQuantity) {
            throw ValidationException::withMessages(["{$path}.quantity" => 'A quantidade do item deve ser um número inteiro de unidades igual à quantidade do nesting.']);
        }
    }

    /** Valida o preço por área independentemente de nesting; cópias precisam fechar sem arredondamento. */
    private function assertCommercialAreaQuantity(QuotePreset $product, array $answers, int $lineQuantityMilli, int $index): ?int
    {
        if ($product->unit !== 'm²') return null;

        $width = $this->meterMilli($answers['width_m'] ?? null);
        $height = $this->meterMilli($answers['height_m'] ?? null);
        if ($width === null || $height === null || $width > 10_000 || $height > 10_000 || ($width * $height) % 1000 !== 0) {
            throw ValidationException::withMessages(["items.{$index}.quantity" => 'O preço por m² exige dimensões cuja área seja exata em milésimos.']);
        }
        $areaMilli = intdiv($width * $height, 1000);
        if ($areaMilli < 1 || $lineQuantityMilli % $areaMilli !== 0) {
            throw ValidationException::withMessages(["items.{$index}.quantity" => 'A quantidade em m² deve equivaler exatamente à área das dimensões multiplicada por um número inteiro de cópias.']);
        }

        return intdiv($lineQuantityMilli, $areaMilli);
    }

    private function answerDimensionsMm(QuotePreset $product, array $answers): ?array
    {
        [$widthKey, $heightKey, $scale] = match ($product->code) {
            'sign-facade', 'product-frontlight-banner', 'product-printed-adhesive' => ['width_m', 'height_m', 'meter'],
            'print-business-card', 'product-acrylic-cutout' => ['width_mm', 'height_mm', 'millimeter'],
            'product-presentation-folder', 'product-folder-print' => ['open_width_mm', 'open_height_mm', 'millimeter'],
            'product-flyer' => ['width_mm', 'height_mm', 'millimeter'],
            'product-roll-up' => ['width_mm', 'height_mm', 'millimeter'],
            'product-labels-roll-sheet' => ['width_mm', 'height_mm', 'millimeter'],
            default => [null, null, null],
        };
        if ($widthKey === null || $heightKey === null) return null;
        $width = $scale === 'meter' ? $this->meterMilli($answers[$widthKey] ?? null) : $this->millimeterInteger($answers[$widthKey] ?? null);
        $height = $scale === 'meter' ? $this->meterMilli($answers[$heightKey] ?? null) : $this->millimeterInteger($answers[$heightKey] ?? null);
        return $width !== null && $height !== null && $width <= 10_000 && $height <= 10_000 ? [$width, $height] : null;
    }

    /** O catálogo ainda contém schemas com mais opções do que componentes precificados vinculáveis. */
    private function expectedNestingMaterial(QuotePreset $product, array $answers, string $path): string
    {
        $expected = match ($product->code) {
            'sign-facade' => match ($answers['acm_thickness'] ?? null) { '3mm' => 'material-acm-3mm', '4mm' => 'material-acm-4mm', default => null },
            'product-frontlight-banner' => match ($answers['material'] ?? null) {
                'frontlight-440g' => 'material-frontlight-440g', 'frontlight-500g' => 'material-frontlight-500g',
                'backlight' => 'material-backlight', 'mesh' => 'material-mesh', 'sublimation-fabric' => 'material-sublimation-fabric', default => null,
            },
            'product-roll-up' => 'material-frontlight-440g',
            'product-printed-adhesive' => match ($answers['material'] ?? null) {
                'monomeric' => 'material-vinyl-monomeric', 'polymeric' => 'material-vinyl-polymeric',
                'perforated' => 'material-vinyl-perforated', 'frosted' => 'material-vinyl-frosted',
                'static-cling' => 'material-vinyl-static-cling', default => null,
            },
            'print-business-card' => match ($answers['stock'] ?? null) {
                'couche-250g' => 'material-cardstock-250g', 'couche-300g' => 'material-cardstock-300g', 'pvc-075' => 'material-card-pvc-075', default => null,
            },
            'product-presentation-folder' => match ($answers['stock'] ?? null) { 'couche-300g' => 'material-couche-300g', default => null },
            'product-flyer', 'product-folder-print' => match ($answers['stock'] ?? null) {
                'couche-250g' => 'material-cardstock-250g', 'couche-300g' => 'material-couche-300g',
                'offset-90g' => 'material-offset-90g', default => null,
            },
            'product-labels-roll-sheet' => match ($answers['format'] ?? null) {
                'roll' => 'material-label-roll-stock', 'sheet' => 'material-label-sheet-stock', default => null,
            },
            'product-acrylic-cutout' => match ($answers['plastic_type'] ?? null) {
                'acrylic-crystal', 'acrylic-color' => match ((string) ($answers['thickness_mm'] ?? '')) {
                    '2' => 'material-acrylic-cast-2mm', '3' => 'material-acrylic-cast-3mm', '4' => 'material-acrylic-cast-4mm',
                    '5' => 'material-acrylic-cast-5mm', '6' => 'material-acrylic-cast-6mm', '8' => 'material-acrylic-cast-8mm',
                    '10' => 'material-acrylic-cast-10mm', default => null,
                },
                'ps' => 'material-ps-sheet',
                'expanded-pvc' => 'material-expanded-pvc-sheet',
                'polycarbonate' => 'material-polycarbonate-sheet',
                default => null,
            },
            default => null,
        };
        if ($expected === null) {
            throw ValidationException::withMessages([$path => 'O produto ou material escolhido não possui vínculo de nesting confiável. Use a estimativa standalone ou consumo manual.']);
        }
        return $expected;
    }

    private function millimeterInteger(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) return null;
        $value = (string) $value;
        if (! preg_match('/\A\d{1,7}(?:[.,]\d{1,3})?\z/', $value)) return null;
        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $value, 2), 2, '0');
        $milli = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        return $milli % 1000 === 0 ? intdiv($milli, 1000) : null;
    }

    private function meterMilli(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) return null;
        $value = (string) $value;
        if (! preg_match('/\A\d{1,7}(?:[.,]\d{1,3})?\z/', $value)) return null;
        [$whole, $fraction] = array_pad(preg_split('/[.,]/', $value, 2), 2, '0');
        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    private function nestingInteger(array $nesting, string $key, string $path, ?int $default = null): int
    {
        $value = $nesting[$key] ?? $default;
        if (! is_int($value) && !(is_string($value) && preg_match('/\A\d{1,6}\z/', $value))) {
            throw ValidationException::withMessages(["{$path}.{$key}" => 'Use um número inteiro em milímetros para as dimensões do nesting.']);
        }
        return (int) $value;
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
