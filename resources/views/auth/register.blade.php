@extends('layouts.auth')
@section('title', 'Criar conta')
@section('content')
<span class="eyebrow accent">UM NOVO COMEÇO</span><h1>Crie seu espaço.</h1><p class="muted">Cadastre sua gráfica e comece a organizar seus clientes.</p>
@include('partials.errors')
<form method="POST" action="{{ route('register.store') }}" class="auth-form">@csrf
<x-field name="organization_name" label="Nome da gráfica" autocomplete="organization" :required="true" maxlength="255"/>
<x-field name="name" label="Seu nome" autocomplete="name" :required="true" maxlength="255"/>
<x-field name="email" label="E-mail" type="email" autocomplete="email" :required="true"/>
<div class="form-grid"><x-field name="password" label="Senha" type="password" autocomplete="new-password" :required="true"/><x-field name="password_confirmation" label="Confirme a senha" type="password" autocomplete="new-password" :required="true"/></div>
<button class="button primary full" type="submit">Criar minha conta<x-icon name="arrow"/></button>
</form><p class="auth-switch">Já possui uma conta? <a href="{{ route('login') }}">Entrar</a></p>
@endsection
