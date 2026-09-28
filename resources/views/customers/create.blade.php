@extends('layouts.app')
@section('title', 'Novo cliente')
@section('content')
<a class="back-link" href="{{ route('customers.index') }}">← Voltar para clientes</a><div class="page-heading"><div><span class="eyebrow">SUA BASE DE CLIENTES</span><h1>Novo cliente</h1><p class="muted">O primeiro passo para um atendimento mais próximo.</p></div></div>
<form method="POST" action="{{ route('customers.store') }}" class="customer-form">@csrf @include('customers._form')</form>
@endsection
