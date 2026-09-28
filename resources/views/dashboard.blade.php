@extends('layouts.app')
@section('title', 'Visão geral')
@section('content')
<div class="page-heading"><div><span class="eyebrow">VISÃO GERAL</span><h1>Seu trabalho começa aqui.</h1><p class="muted">Um olhar sobre os clientes da {{ auth()->user()->organization->name }}.</p></div><a class="button primary" href="{{ route('customers.create') }}"><x-icon name="plus"/>Novo cliente</a></div>
<div class="dashboard-grid"><section class="card metric"><span class="metric-icon"><x-icon name="users"/></span><span class="muted">Clientes cadastrados</span><strong class="metric-value">{{ number_format($customerCount, 0, ',', '.') }}</strong><a class="text-link" href="{{ route('customers.index') }}">Ver todos os clientes<x-icon name="arrow"/></a></section><section class="welcome-banner"><span class="eyebrow">CADA CLIENTE CONTA</span><h2>Informação organizada.<br>Atendimento mais próximo.</h2><p>Reúna contatos e observações para encontrar o que precisa na próxima conversa.</p></section></div>
<section class="card"><div class="section-heading"><div><h2>Clientes recentes</h2><p class="muted">Os últimos cadastros da sua gráfica.</p></div><a class="text-link" href="{{ route('customers.index') }}">Ver todos<x-icon name="arrow"/></a></div>
@if($recentCustomers->isEmpty())
<div class="empty-state"><span class="empty-icon"><x-icon name="users"/></span><h3>Sua primeira conexão começa aqui.</h3><p>Ainda não há clientes cadastrados. Adicione o primeiro para começar.</p><a class="button secondary" href="{{ route('customers.create') }}"><x-icon name="plus"/>Cadastrar primeiro cliente</a></div>
@else
<div class="customer-list">@foreach($recentCustomers as $customer)<a class="customer-row" href="{{ route('customers.show', $customer) }}"><span class="avatar coral">{{ mb_substr($customer->name, 0, 1) }}</span><span class="customer-identity"><strong>{{ $customer->name }}</strong><span>{{ $customer->email ?: 'E-mail não informado' }}</span></span><span class="badge">{{ $customer->type === 'company' ? 'Empresa' : 'Pessoa física' }}</span><x-icon name="arrow"/></a>@endforeach</div>
@endif</section>
@endsection
