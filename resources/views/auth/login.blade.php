@extends('layouts.auth')
@section('title', 'Entrar')
@section('content')
<span class="eyebrow accent">BEM-VINDO AO PRINTPRO</span><h1>Sua gráfica, conectada.</h1><p class="muted">Entre para acessar seu espaço de trabalho.</p>
@include('partials.errors')
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
<form method="POST" action="{{ route('login.store') }}" class="auth-form">@csrf
<x-field name="email" label="E-mail" type="email" autocomplete="email" :required="true" autofocus/>
<x-field name="password" label="Senha" type="password" autocomplete="current-password" :required="true"/>
<label class="checkbox"><input type="checkbox" name="remember" value="1" @checked(old('remember'))>Manter conectado neste dispositivo</label>
<button class="button primary full" type="submit">Entrar no printPRO<x-icon name="arrow"/></button>
</form><p class="auth-switch">Sua gráfica ainda não tem conta? <a href="{{ route('register') }}">Comece por aqui</a></p>
@endsection
