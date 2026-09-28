@extends('layouts.app')
@section('title', 'Editar cliente')
@section('content')
<a class="back-link" href="{{ route('customers.show', $customer) }}">← Voltar para o cliente</a><div class="page-heading"><div><span class="eyebrow">SUA BASE DE CLIENTES</span><h1>Editar cliente</h1><p class="muted">Mantenha os dados de {{ $customer->name }} atualizados.</p></div></div>
<form method="POST" action="{{ route('customers.update', $customer) }}" class="customer-form">@csrf @method('PUT') @include('customers._form')</form>
@endsection
