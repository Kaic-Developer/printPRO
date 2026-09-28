@extends('layouts.app')
@section('title', 'Novo orçamento')
@section('content')
@php
    $enabledPresets = collect($presets ?? []);
    $enabledComponents = collect($components ?? []);
    $customerList = collect($customers ?? []);
    $pricingReady = !empty($pricing) && data_get($pricing, 'waste_basis_points') !== null && data_get($pricing, 'markup_multiplier_basis_points') !== null;
    // O schema é declarativo e validado no servidor; os controles enviam somente respostas do produto escolhido.
    $renderWizardField = static function ($field) {
        $key = data_get($field, 'key');
        $type = data_get($field, 'type', 'text');
        $name = 'items[__INDEX__][answers]['.$key.']';
        $id = 'wizard-__INDEX__-'.$key;
        $required = (bool) data_get($field, 'required', false);
        if ($type === 'quantity' || $key === 'quantity') return;
        echo '<div class="field wizard-field" data-wizard-field="'.e($key).'"';
        if ($condition = data_get($field, 'visible_when')) {
            echo ' data-visible-field="'.e(data_get($condition, 'field')).'"';
            if (isset($condition['in'])) echo ' data-visible-in="'.e(implode(',', $condition['in'])).'"';
            else echo ' data-visible-equals="'.e(data_get($condition, 'equals')).'"';
        }
        echo '><label for="'.e($id).'">'.e(data_get($field, 'label', $key)).($required ? ' <span class="required" aria-hidden="true">*</span>' : '').'</label>';
        if ($type === 'size_grid') {
            // O backend soma as variantes para definir a quantidade da linha e rejeita grades sem peças.
            echo '<div class="size-grid-inputs">';
            foreach ((array) data_get($field, 'sizes', []) as $size) {
                $sizeValue = data_get($size, 'value');
                $sizeLabel = data_get($size, 'label', $sizeValue);
                $sizeId = $id.'-'.\Illuminate\Support\Str::slug((string) $sizeValue);
                echo '<label class="size-grid-cell" for="'.e($sizeId).'"><span>'.e($sizeLabel).'</span><input id="'.e($sizeId).'" name="'.e($name).'['.e($sizeValue).']" type="number" inputmode="numeric" min="0" step="1" placeholder="0"></label>';
            }
            echo '</div><span class="field-hint">Informe as quantidades desejadas; elas serão somadas para a produção.</span>';
        } elseif ($type === 'multiselect') {
            echo '<select id="'.e($id).'" name="'.e($name).'[]" multiple'.($required ? ' required' : '').'>';
            foreach ((array) data_get($field, 'options', []) as $option) echo '<option value="'.e(data_get($option, 'value')).'">'.e(data_get($option, 'label', data_get($option, 'value'))).'</option>';
            echo '</select><span class="field-hint">Use Ctrl (Windows) ou Command (Mac) para selecionar mais de uma opção.</span>';
        } elseif ($type === 'select') {
            echo '<select id="'.e($id).'" name="'.e($name).'"'.($required ? ' required' : '').'><option value="">Selecione</option>';
            foreach ((array) data_get($field, 'options', []) as $option) echo '<option value="'.e(data_get($option, 'value')).'">'.e(data_get($option, 'label', data_get($option, 'value'))).'</option>';
            echo '</select>';
        } elseif ($type === 'boolean') {
            echo '<select id="'.e($id).'" name="'.e($name).'"'.($required ? ' required' : '').'><option value="">Selecione</option><option value="1">Sim</option><option value="0">Não</option></select>';
        } elseif ($type === 'checkbox') {
            echo '<label class="wizard-boolean" for="'.e($id).'"><input id="'.e($id).'" type="checkbox" name="'.e($name).'" value="1">Sim</label>';
        } else {
            $htmlType = in_array($type, ['integer', 'decimal', 'number'], true) ? 'number' : 'text';
            echo '<input id="'.e($id).'" name="'.e($name).'" type="'.e($htmlType).'"'.($htmlType === 'number' ? ' step="'.($type === 'integer' ? '1' : 'any').'"' : '').(data_get($field, 'min') !== null ? ' min="'.e(data_get($field, 'min')).'"' : '').(data_get($field, 'max') !== null ? ' max="'.e(data_get($field, 'max')).'"' : '').($required ? ' required' : '').'>';
        }
        if ($condition) echo '<span class="field-hint">Aplicável quando '.e(data_get($condition, 'field')).(isset($condition['in']) ? ' é '.e(implode(' ou ', $condition['in'])) : ' = '.e(data_get($condition, 'equals'))).'.</span>';
        elseif ($hint = data_get($field, 'help')) echo '<span class="field-hint">'.e($hint).'</span>';
        echo '</div>';
    };
@endphp
<a class="back-link" href="{{ route('quotes.index') }}">← Voltar para orçamentos</a>
<div class="page-heading"><div><span class="eyebrow">ORÇAMENTAÇÃO INTELIGENTE</span><h1>Novo orçamento</h1><p class="muted">Escolha cliente e quantos produtos precisar; cada linha tem sua ficha técnica.</p></div></div>
@include('partials.errors')
@if(!$pricingReady)<div class="quote-alert quote-alert-warning"><span class="quote-alert-icon">!</span><div><strong>Parâmetros de preço pendentes</strong><p>Configure perda e multiplicador nas <a href="{{ route('quote-settings.index') }}">configurações do catálogo</a>. Sem esses fatores o preço final fica bloqueado.</p></div></div>@endif
<form method="POST" action="{{ route('quotes.store') }}" class="quote-builder">@csrf
    <section class="card quote-step-card"><div class="quote-step-heading"><span class="quote-step-number">01</span><div><span class="eyebrow">ATENDIMENTO</span><h2>Para quem estamos orçando?</h2><p>Associe a proposta a um cliente cadastrado e defina o prazo de validade se necessário.</p></div></div><div class="quote-step-body"><div class="form-grid"><div class="field"><label for="customer_id">Cliente <span class="required">*</span></label><select id="customer_id" name="customer_id" required><option value="">Selecione um cliente</option>@foreach($customerList as $customer)<option value="{{ data_get($customer, 'id') }}" @selected((string) old('customer_id') === (string) data_get($customer, 'id'))>{{ data_get($customer, 'name') }}</option>@endforeach</select>@if($customerList->isEmpty())<span class="field-hint">Cadastre um cliente para continuar.</span>@endif</div><div class="field"><label for="expires_at">Válido até (opcional)</label><input id="expires_at" name="expires_at" type="date" min="{{ now()->toDateString() }}" value="{{ old('expires_at') }}"></div></div></div></section>
    <section class="card quote-step-card"><div class="quote-step-heading"><span class="quote-step-number">02</span><div><span class="eyebrow">PRODUTOS E ESPECIFICAÇÕES</span><h2>Monte a proposta</h2><p>Adicione uma ou mais linhas. O formulário abre as perguntas e componentes do produto selecionado.</p></div></div>
        @if($enabledPresets->isNotEmpty())
            <div class="quote-lines" data-quote-lines><div data-quote-line-list></div><button class="button secondary quote-add-line" type="button" data-add-quote-line><x-icon name="plus"/>Adicionar produto</button></div>
        @else
            <div class="empty-state"><span class="empty-icon"><x-icon name="catalog"/></span><h3>Nenhum produto disponível</h3><p>Ative produtos no catálogo técnico antes de iniciar um orçamento.</p><a class="button secondary" href="{{ route('quote-settings.index') }}">Abrir configurações</a></div>
        @endif
    </section>
    @if($enabledPresets->isNotEmpty())<script type="application/json" id="quote-old-items">@json(old('items', []))</script><section class="card quote-submit-card"><div><h2>Revisar antes de calcular</h2><p>Custos ou consumos ausentes bloqueiam o preço. Informe somente os componentes usados.</p></div><button class="button primary" type="submit">Salvar orçamento<x-icon name="arrow"/></button></section>@endif
</form>
@if($enabledPresets->isNotEmpty())
<template id="quote-line-template"><article class="quote-line-card" data-quote-line><header class="quote-line-head"><span class="quote-step-number" data-line-number>01</span><div><strong>Produto da proposta</strong><small>Uma linha pode conter quantidades e acabamentos próprios.</small></div><button type="button" class="quote-remove-line" data-remove-quote-line aria-label="Remover produto">Remover</button></header><div class="quote-line-body"><div class="field"><label data-product-label for="product-__INDEX__">Produto <span class="required">*</span></label><select id="product-__INDEX__" name="items[__INDEX__][preset_code]" data-preset-select required><option value="">Selecione um produto ativo</option>@foreach($enabledPresets as $preset)<option value="{{ data_get($preset, 'code') }}">{{ data_get($preset, 'name') }}</option>@endforeach</select></div>
@foreach($enabledPresets as $preset)
    @php
        $presetCode = data_get($preset, 'code');
        $schemaFields = collect(data_get($preset, 'wizard_schema.fields', []));
        $sizeGrid = $schemaFields->first(fn ($field) => data_get($field, 'type') === 'size_grid');
        $quantitySchema = $schemaFields->first(fn ($field) => data_get($field, 'type') === 'quantity' || data_get($field, 'key') === 'quantity');
        $suggestedCodes = (array) data_get($preset, 'suggested_components', []);
        $nestingCodeAllowlist = [
            'sign-facade' => ['material-acm-3mm', 'material-acm-4mm'],
            'product-frontlight-banner' => ['material-frontlight-440g', 'material-frontlight-500g', 'material-backlight', 'material-mesh', 'material-sublimation-fabric'],
            'product-printed-adhesive' => ['material-vinyl-monomeric', 'material-vinyl-polymeric', 'material-vinyl-perforated', 'material-vinyl-frosted', 'material-vinyl-static-cling'],
            'print-business-card' => ['material-cardstock-250g', 'material-cardstock-300g', 'material-card-pvc-075'],
            'product-presentation-folder' => ['material-couche-300g'],
            'product-acrylic-cutout' => ['material-acrylic-sheet', 'material-acrylic-cast-2mm', 'material-acrylic-cast-3mm', 'material-acrylic-cast-4mm', 'material-acrylic-cast-5mm', 'material-acrylic-cast-6mm', 'material-acrylic-cast-8mm', 'material-acrylic-cast-10mm', 'material-ps-sheet', 'material-expanded-pvc-sheet', 'material-polycarbonate-sheet'],
        ];
        $allowedNestingCodes = $nestingCodeAllowlist[$presetCode] ?? [];
        $nestingMaterials = collect($suggestedCodes)
            ->map(fn ($code) => $enabledComponents->firstWhere('code', $code))
            ->filter(fn ($component) => $component
                && in_array(data_get($component, 'code'), $allowedNestingCodes, true)
                && data_get($component, 'kind') === 'material'
                && data_get($component, 'tenant_enabled')
                && data_get($component, 'material_width_mm') !== null
                && (in_array(data_get($component, 'unit'), ['m', 'm²'], true) || data_get($component, 'material_length_mm') !== null))
            ->values();
    @endphp
    <div class="quote-preset-panel" data-preset-panel="{{ $presetCode }}" hidden>
        @if(!$sizeGrid)<div class="field quote-quantity-field"><label for="quantity-__INDEX__-{{ $presetCode }}">{{ data_get($quantitySchema, 'label', 'Quantidade') }} <span class="required">*</span></label><input id="quantity-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][quantity]" type="number" inputmode="decimal" min="{{ data_get($quantitySchema, 'min', 0.001) }}" step="{{ data_get($quantitySchema, 'type') === 'integer' ? '1' : '0.001' }}" placeholder="Informe a quantidade" required><span class="field-hint">Unidade de venda: {{ data_get($preset, 'unit', 'não definida') }}</span></div>@endif
        @if($schemaFields->isNotEmpty())<details class="quote-wizard-details" open><summary>Ficha técnica <span>{{ $schemaFields->count() }} campo(s)</span></summary><div class="quote-wizard-fields">@foreach($schemaFields as $field){{ $renderWizardField($field) }}@endforeach</div></details>@endif
        @if(count($suggestedCodes))<details class="quote-wizard-details quote-components-details" open><summary>Insumos e processos sugeridos <span>{{ count($suggestedCodes) }} componente(s)</span></summary><p class="wizard-intro">Marque o que será usado e informe o consumo por unidade produzida. Inclua pelo menos um material e um processo/acabamento. A unidade de compra pode ser diferente da unidade de venda.</p><div class="wizard-component-list">
            @foreach($suggestedCodes as $componentIndex => $componentCode)
                @php($component = $enabledComponents->firstWhere('code', $componentCode))
                @if($component)<article @class(['wizard-component-row', 'wizard-component-disabled' => !data_get($component, 'tenant_enabled')]) data-component-code="{{ $componentCode }}">
                    @if(data_get($component, 'tenant_enabled'))<input type="hidden" name="items[__INDEX__][components][{{ $componentIndex }}][code]" value="{{ $componentCode }}"><label class="quote-component-check"><input type="checkbox" name="items[__INDEX__][components][{{ $componentIndex }}][selected]" value="1"><span><strong>{{ data_get($component, 'name') }}</strong><small>{{ data_get($component, 'production_sector', 'Insumo/processo') }} · unidade de custo: {{ data_get($component, 'unit', 'não definida') }}</small><small class="component-match-label" data-component-match-label hidden>Relacionado à opção selecionada; confirme se será usado.</small><small class="nesting-managed-label">Consumo total calculado pela estimativa de aproveitamento.</small><small class="wizard-managed-label" data-wizard-managed-label hidden>Consumo estimado pela ficha técnica, arredondado para cima e multiplicado pela grade.</small></span></label><div class="wizard-component-quantity"><label for="component-qty-__INDEX__-{{ $componentIndex }}">Consumo por unidade</label><input id="component-qty-__INDEX__-{{ $componentIndex }}" name="items[__INDEX__][components][{{ $componentIndex }}][quantity]" type="number" inputmode="decimal" min="0.001" step="0.001" placeholder="Informe"><span class="field-hint" data-component-quantity-hint>{{ data_get($component, 'unit_cost_cents') === null ? 'Custo não configurado: cálculo bloqueado' : 'Custo unitário cadastrado' }}</span></div>
                    @else<span class="quote-component-unavailable"><strong>{{ data_get($component, 'name') }}</strong><small>Componente desativado nesta gráfica. Reative-o no catálogo técnico para incluí-lo.</small></span>@endif
                </article>@endif
            @endforeach
        </div></details>@else<p class="quote-settings-empty">Este produto ainda não tem componentes sugeridos. A ficha técnica precisa ser configurada para calcular custos.</p>@endif
        @if($nestingMaterials->isNotEmpty())
            <section class="quote-nesting-editor" data-nesting-editor aria-labelledby="nesting-heading-__INDEX__-{{ $presetCode }}">
                <label class="quote-nesting-toggle"><input type="checkbox" data-nesting-toggle aria-controls="nesting-fields-__INDEX__-{{ $presetCode }}"><span><strong id="nesting-heading-__INDEX__-{{ $presetCode }}">Estimar aproveitamento do material</strong><small>Opcional. Informa quantas chapas ou quanto de bobina pode ser necessário.</small></span></label>
                <div class="quote-nesting-fields" id="nesting-fields-__INDEX__-{{ $presetCode }}" data-nesting-fields hidden>
                    <div class="form-grid"><div class="field"><label for="nesting-material-__INDEX__-{{ $presetCode }}">Material usado nesta estimativa <span class="required">*</span></label><select id="nesting-material-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][material_code]" data-nesting-field="material_code" required disabled><option value="">Selecione um material</option>@foreach($nestingMaterials as $material)<option value="{{ data_get($material, 'code') }}" data-unit="{{ data_get($material, 'unit') }}" data-stock-width="{{ data_get($material, 'material_width_mm') }}" data-stock-length="{{ data_get($material, 'material_length_mm') }}">{{ data_get($material, 'name') }} ({{ data_get($material, 'unit', 'unidade') }})</option>@endforeach</select><span class="field-hint">As medidas nominais são configuradas no catálogo da empresa.</span></div><div class="field"><label for="nesting-type-__INDEX__-{{ $presetCode }}">Formato do material <span class="required">*</span></label><select id="nesting-type-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][material_type]" data-nesting-field="material_type" required disabled><option value="">Selecione</option><option value="sheet">Chapa/folha</option><option value="roll">Bobina</option></select></div></div>
                    <div class="form-grid quote-nesting-dimensions"><div class="field"><label for="nesting-piece-width-__INDEX__-{{ $presetCode }}">Largura da peça (mm) <span class="required">*</span></label><input id="nesting-piece-width-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][piece_width_mm]" data-nesting-field="piece_width_mm" type="number" inputmode="numeric" min="1" max="10000" step="1" required disabled></div><div class="field"><label for="nesting-piece-length-__INDEX__-{{ $presetCode }}">Comprimento da peça (mm) <span class="required">*</span></label><input id="nesting-piece-length-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][piece_length_mm]" data-nesting-field="piece_length_mm" type="number" inputmode="numeric" min="1" max="10000" step="1" required disabled></div><div class="field"><label for="nesting-material-width-__INDEX__-{{ $presetCode }}">Largura cadastrada do material (mm)</label><input id="nesting-material-width-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][material_width_mm]" data-nesting-field="material_width_mm" type="number" inputmode="numeric" min="1" max="10000" step="1" readonly required disabled></div><div class="field" data-nesting-sheet-length><label for="nesting-material-length-__INDEX__-{{ $presetCode }}">Comprimento cadastrado da chapa (mm)</label><input id="nesting-material-length-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][material_length_mm]" data-nesting-field="material_length_mm" type="number" inputmode="numeric" min="1" max="10000" step="1" readonly disabled></div><div class="field"><label for="nesting-quantity-__INDEX__-{{ $presetCode }}">Cópias ou peças para encaixar <span class="required">*</span></label><input id="nesting-quantity-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][quantity]" data-nesting-field="quantity" type="number" inputmode="numeric" min="1" max="100000" step="1" required disabled><span class="field-hint">Informe quantas peças serão recortadas, mesmo quando o produto é vendido por área.</span></div><div class="field"><label for="nesting-gap-__INDEX__-{{ $presetCode }}">Folga entre peças (mm)</label><input id="nesting-gap-__INDEX__-{{ $presetCode }}" name="items[__INDEX__][nesting][gap_mm]" data-nesting-field="gap_mm" type="number" inputmode="numeric" min="0" max="10000" step="1" value="0" disabled></div></div>
                    <button type="button" class="button secondary quote-nesting-preview" data-nesting-preview data-endpoint="{{ route('quotes.nesting') }}">Pré-visualizar aproveitamento</button><p class="quote-nesting-result" data-nesting-result role="status" aria-live="polite">Preencha as dimensões para ver uma estimativa simplificada.</p>
                </div>
            </section>
        @elseif($allowedNestingCodes)
            <p class="quote-nesting-unavailable">Configure as dimensões nominais do material nas configurações do catálogo para liberar o nesting integrado.</p>
        @endif
    </div>
@endforeach
</div></article></template>
@endif
@endsection
