@extends('layouts.app')
@section('title', 'Configurações de orçamento')
@section('content')
@php
    // Custos pertencem à empresa; nenhum preço, perda ou multiplicador é presumido pelo catálogo.
    $formatCents = static function ($cents) {
        if ($cents === null) return '';
        return number_format(intdiv((int) $cents, 100), 0, ',', '.').','.str_pad((string) (((int) $cents) % 100), 2, '0', STR_PAD_LEFT);
    };
    $catalogCategories = collect($categories ?? []);
    $pricingSettings = $pricing ?? null;
@endphp
<div class="page-heading quote-heading"><div><span class="eyebrow">ORÇAMENTAÇÃO · CONFIGURAÇÕES</span><h1>Catálogo técnico</h1><p class="muted">Ative os serviços que sua empresa oferece e informe custos conhecidos por componente.</p></div><a class="button secondary" href="{{ route('quotes.index') }}"><x-icon name="quote"/>Ver orçamentos</a></div>
@include('partials.errors')
<section class="quote-intro-banner"><div><span class="eyebrow">SEU CATÁLOGO, SUAS REGRAS</span><h2>Prepare a base antes de precificar.</h2><p>Custos e fatores comerciais ficam sem valores iniciais. O sistema sinaliza o que ainda precisa ser configurado.</p></div><span class="quote-intro-mark"><x-icon name="catalog"/></span></section>
<section class="card quote-pricing-card"><div class="section-heading"><div><h2>Parâmetros de formação de preço</h2><p class="muted">Fatores comerciais da sua empresa, aplicados aos próximos cálculos.</p></div><span class="badge {{ $pricingSettings ? 'status-active' : 'status-pending' }}">{{ $pricingSettings ? 'Configurado' : 'Pendente' }}</span></div><form method="POST" action="{{ route('quote-settings.update') }}" class="quote-pricing-form">@csrf @method('PUT')<div class="form-grid"><div class="field"><label for="waste_percentage">Perda (%)</label><input id="waste_percentage" name="waste_percentage" type="number" inputmode="decimal" min="0" step="0.01" required placeholder="Informe a perda da sua operação" value="{{ old('waste_percentage', $pricingSettings ? number_format(data_get($pricingSettings, 'waste_basis_points') / 100, 2, '.', '') : '') }}"><span class="field-hint">Percentual aplicado ao custo agregado (materiais, processos e terceiros), conforme a fórmula comercial do orçamento.</span></div><div class="field"><label for="markup_multiplier">Multiplicador de markup</label><input id="markup_multiplier" name="markup_multiplier" type="number" inputmode="decimal" min="0.01" step="0.01" required placeholder="Informe seu multiplicador" value="{{ old('markup_multiplier', $pricingSettings ? number_format(data_get($pricingSettings, 'markup_multiplier_basis_points') / 10000, 2, '.', '') : '') }}"><span class="field-hint">Multiplicador aplicado ao custo total após a perda. Markup não é margem bruta: 2,00× equivale a 50% antes das demais despesas.</span></div></div><div class="quote-item-actions"><span class="field-hint">Não existe valor padrão. Configure os fatores usados pela sua empresa.</span><button type="submit" class="button primary">Salvar parâmetros</button></div></form></section>
@forelse($catalogCategories as $category)
    @php
        $categoryEnabled = (bool) data_get($category, 'is_enabled', false);
        $itemsByGroup = collect(data_get($category, 'items', []))->groupBy('group');
    @endphp
    <section class="card quote-category-card" aria-labelledby="category-{{ data_get($category, 'id') }}">
        <div class="quote-category-head"><div class="quote-category-title"><span class="quote-category-icon"><x-icon name="grid"/></span><div><span class="eyebrow">{{ data_get($category, 'code', 'CATEGORIA') }}</span><h2 id="category-{{ data_get($category, 'id') }}">{{ data_get($category, 'name') }}</h2><p class="muted">Insumos, processos e acabamentos desta área.</p></div></div>
            <form method="POST" action="{{ route('quote-settings.update') }}" class="quote-toggle-form">@csrf @method('PUT')<input type="hidden" name="items[{{ data_get($category, 'code') }}][is_enabled]" value="{{ $categoryEnabled ? 0 : 1 }}"><button class="switch-button {{ $categoryEnabled ? 'switch-on' : '' }}" type="submit" aria-label="{{ $categoryEnabled ? 'Desativar' : 'Ativar' }} categoria {{ data_get($category, 'name') }}"><span></span><strong>{{ $categoryEnabled ? 'Ativa' : 'Inativa' }}</strong></button></form>
        </div>
        <div class="quote-settings-items">
        @forelse($itemsByGroup as $groupName => $groupItems)
            <section class="quote-settings-group"><h3>{{ $groupName ?: data_get($category, 'name') }}</h3>
            @foreach($groupItems as $item)
                @php($itemEnabled = (bool) data_get($item, 'is_enabled', false))
                <article class="quote-setting-item">
                    <div class="quote-setting-identity"><span class="quote-item-dot"></span><div><h4>{{ data_get($item, 'name') }}</h4><p>{{ data_get($item, 'unit') ? 'Unidade: '.data_get($item, 'unit') : ucfirst(data_get($item, 'kind', 'categoria')) }}@if(data_get($item, 'production_sector')) · {{ data_get($item, 'production_sector') }}@endif</p>
                        @if(count(data_get($item, 'suggested_components', [])))<span class="quote-component-summary">{{ count(data_get($item, 'suggested_components', [])) }} componente(s) sugerido(s)</span>@endif
                    </div></div>
                    <form method="POST" action="{{ route('quote-settings.update') }}" class="quote-item-form">@csrf @method('PUT')
                        <div class="quote-setting-fields">@if(in_array(data_get($item, 'kind'), ['material', 'process', 'finish', 'third_party'], true))<div class="field"><label for="cost-{{ data_get($item, 'id') }}">Custo unitário (opcional)</label><div class="money-input"><span>R$</span><input id="cost-{{ data_get($item, 'id') }}" name="items[{{ data_get($item, 'code') }}][unit_cost]" type="text" inputmode="decimal" autocomplete="off" data-money-input placeholder="Deixe vazio" value="{{ old('items.'.data_get($item, 'code').'.unit_cost', $formatCents(data_get($item, 'unit_cost_cents'))) }}" aria-describedby="cost-help-{{ data_get($item, 'id') }}"></div><span class="field-hint" id="cost-help-{{ data_get($item, 'id') }}">{{ data_get($item, 'unit_cost_cents') === null ? 'Custo não configurado: cálculo bloqueado' : 'Valor salvo em centavos' }}</span></div>@endif</div>
                        <div class="quote-item-actions"><span><input type="hidden" name="items[{{ data_get($item, 'code') }}][is_enabled]" value="0"><label class="quote-enable-check"><input type="checkbox" name="items[{{ data_get($item, 'code') }}][is_enabled]" value="1" @checked($itemEnabled)>Disponível nos orçamentos</label></span><button type="submit" class="button secondary">Salvar item</button></div>
                    </form>
                </article>
            @endforeach
            </section>
        @empty<p class="quote-settings-empty">Nenhum item pré-cadastrado nesta categoria.</p>@endforelse
        </div>
    </section>
@empty
    <section class="card empty-state"><span class="empty-icon"><x-icon name="catalog"/></span><h3>Catálogo em preparação</h3><p>As categorias serão exibidas aqui quando os modelos técnicos forem carregados.</p></section>
@endforelse
<p class="quote-footnote">Custos ausentes e quantidades de consumo não definidas impedem um cálculo confiável. O catálogo não presume preço nem rendimento.</p>
@endsection
