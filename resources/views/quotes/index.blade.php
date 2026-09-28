@extends('layouts.app')
@section('title', 'Orçamentos')
@section('content')
@php($quoteRows = $quotes ?? collect())
<div class="page-heading"><div><span class="eyebrow">VENDAS E PRODUÇÃO</span><h1>Orçamentos</h1><p class="muted">Acompanhe propostas, revisões e ordens de produção.</p></div><a class="button primary" href="{{ route('quotes.create') }}"><x-icon name="plus"/>Novo orçamento</a></div>
@include('partials.errors')
<section class="card quote-list-card"><div class="section-heading"><div><h2>Propostas da empresa</h2><p class="muted">{{ method_exists($quoteRows, 'total') ? $quoteRows->total() : $quoteRows->count() }} registro(s)</p></div></div>
@if($quoteRows->isEmpty())
    <div class="empty-state"><span class="empty-icon"><x-icon name="quote"/></span><h3>Nenhum orçamento por enquanto</h3><p>Monte uma proposta com ficha técnica, consumo de insumos e custos configurados.</p><a class="button secondary" href="{{ route('quotes.create') }}">Criar primeiro orçamento</a></div>
@else
    <div class="table-scroll"><table class="quote-table"><thead><tr><th scope="col">Orçamento</th><th scope="col">Cliente</th><th scope="col">Atualizado</th><th scope="col">Versão</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead><tbody>
    @foreach($quoteRows as $quote)
        @php
            $status = data_get($quote, 'status', 'draft');
            $statusLabels = ['draft' => 'Em edição', 'pending' => 'Aguardando aprovação', 'sent' => 'Enviado', 'approved' => 'Aprovado', 'rejected' => 'Recusado', 'expired' => 'Expirado'];
            $statusClass = $status === 'approved' ? 'status-active' : (in_array($status, ['rejected', 'expired'], true) ? 'status-danger' : 'status-pending');
            $saleCents = data_get($quote, 'currentVersionRecord.sale_total_cents');
        @endphp
        <tr><td><a class="quote-row-name" href="{{ route('quotes.show', $quote) }}">{{ data_get($quote, 'number', 'Orçamento #'.data_get($quote, 'id')) }}</a><span class="table-subtitle">{{ $saleCents !== null ? 'Preço: R$ '.number_format(intdiv((int) $saleCents, 100), 0, ',', '.').','.str_pad((string) (((int) $saleCents) % 100), 2, '0', STR_PAD_LEFT) : 'Preço pendente de configuração' }}</span></td><td>{{ data_get($quote, 'customer.name', 'Cliente') }}</td><td>{{ data_get($quote, 'updated_at') ? data_get($quote, 'updated_at')->format('d/m/Y') : '—' }}</td><td>{{ data_get($quote, 'currentVersionRecord.version_number', data_get($quote, 'current_version', '—')) }}</td><td><span class="badge {{ $statusClass }}">{{ $statusLabels[$status] ?? str_replace('_', ' ', ucfirst($status)) }}</span></td><td class="text-right"><a class="text-link" href="{{ route('quotes.show', $quote) }}">Abrir<x-icon name="arrow"/></a></td></tr>
    @endforeach
    </tbody></table></div>
    @if(method_exists($quoteRows, 'links')){{ $quoteRows->links('partials.pagination', ['paginationLabel' => 'Paginação dos orçamentos']) }}@endif
@endif
</section>
@endsection
