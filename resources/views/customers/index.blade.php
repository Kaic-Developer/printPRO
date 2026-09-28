@extends('layouts.app')
@section('title', 'Clientes')
@section('content')
<div class="page-heading"><div><span class="eyebrow">RELACIONAMENTOS</span><h1>Clientes</h1><p class="muted">Contatos organizados, sempre por perto.</p></div><a class="button primary" href="{{ route('customers.create') }}"><x-icon name="plus"/>Novo cliente</a></div>
@include('partials.errors')
<section class="card"><div class="list-toolbar"><div><h2>Sua base de clientes</h2><p class="muted">{{ $customers->total() }} {{ $customers->total() === 1 ? 'cliente encontrado' : 'clientes encontrados' }}</p></div><form class="search-form" method="GET" action="{{ route('customers.index') }}"><label class="sr-only" for="q">Buscar clientes</label><div class="search-input"><x-icon name="search"/><input id="q" name="q" type="search" maxlength="255" value="{{ request('q') }}" placeholder="Buscar cliente"></div><button class="button secondary" type="submit">Buscar</button></form></div>
@if(request()->filled('q'))<div class="search-context">Resultados para “{{ request('q') }}” <a href="{{ route('customers.index') }}">Limpar busca</a></div>@endif
@if($customers->isEmpty())
<div class="empty-state"><span class="empty-icon"><x-icon name="users"/></span><h3>{{ request()->filled('q') ? 'Nenhum cliente encontrado.' : 'Vamos conhecer seu primeiro cliente?' }}</h3><p>{{ request()->filled('q') ? 'Tente buscar por outro termo ou limpe a busca para ver todos os cadastros.' : 'Cadastre seus clientes para reunir contatos e observações em um só lugar.' }}</p>@unless(request()->filled('q'))<a class="button secondary" href="{{ route('customers.create') }}">Cadastrar cliente<x-icon name="plus"/></a>@endunless</div>
@else
<div class="table-scroll"><table><thead><tr><th scope="col">Cliente</th><th scope="col">Tipo</th><th scope="col">Contato</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead><tbody>@foreach($customers as $customer)<tr><td><a class="table-name" href="{{ route('customers.show', $customer) }}"><span class="avatar coral">{{ mb_substr($customer->name, 0, 1) }}</span>{{ $customer->name }}</a></td><td><span class="badge">{{ $customer->type === 'company' ? 'Empresa' : 'Pessoa física' }}</span></td><td><span class="contact-line">{{ $customer->email ?: 'E-mail não informado' }}</span><span class="muted small">{{ $customer->phone ?: 'Telefone não informado' }}</span></td><td><a class="text-link" href="{{ route('customers.show', $customer) }}" aria-label="Ver cliente {{ $customer->name }}">Ver<x-icon name="arrow"/></a></td></tr>@endforeach</tbody></table></div>
{{ $customers->withQueryString()->links('partials.pagination') }}
@endif</section>
@endsection
