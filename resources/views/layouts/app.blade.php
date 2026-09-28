<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Painel') · printPRO</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body>
<a class="skip-link" href="#main">Pular para o conteúdo</a>
<div class="app-shell">
<aside class="sidebar" id="navigation">
<a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark"><x-icon name="print"/></span><span>print<strong>PRO</strong></span></a>
<div class="workspace"><span class="eyebrow">SUA GRÁFICA</span><strong>{{ auth()->user()->organization->name }}</strong><span>Seu espaço de trabalho</span></div>
<nav aria-label="Menu principal"><span class="nav-caption">PRINCIPAL</span>
<a href="{{ route('dashboard') }}" @class(['nav-item', 'active' => request()->routeIs('dashboard')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif><x-icon/>Visão geral</a>
<a href="{{ route('customers.index') }}" @class(['nav-item', 'active' => request()->routeIs('customers.*')]) @if(request()->routeIs('customers.*')) aria-current="page" @endif><x-icon name="users"/>Clientes</a>
</nav>
<div class="sidebar-footer"><span class="eyebrow">UM BOM COMEÇO</span><p>Organize seus clientes.<br>Cuide de cada relação.</p><span class="version">WebPrintPRO · Gestão para gráficas</span></div>
</aside>
<div class="main-shell"><header class="topbar"><button class="menu-toggle" type="button" aria-controls="navigation" aria-expanded="false" aria-label="Abrir menu"><x-icon name="menu"/></button><span class="topbar-label">Espaço de trabalho</span><div class="account"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span class="account-name">{{ auth()->user()->name }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit"><x-icon name="exit"/><span>Sair</span></button></form></div></header>
<main id="main" class="content" tabindex="-1">
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
@yield('content')
<footer class="page-footer">printPRO <span>Mais organização para sua gráfica.</span></footer>
</main></div></div>
</body></html>
