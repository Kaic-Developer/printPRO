@extends('layouts.app')
@section('title', 'Módulos')
@section('content')
<div class="page-heading"><div><span class="eyebrow">EVOLUÇÃO DO PRINTPRO</span><h1>Módulos do sistema</h1><p class="muted">Veja o que já está disponível e o que vem nas próximas etapas.</p></div></div>
<section class="module-roadmap-intro"><div><span class="eyebrow">PRÓXIMO PASSO</span><h2>Orçamentos e aprovação</h2><p>O próximo módulo conecta catálogo e clientes em propostas acompanháveis. A formação de preço ficará configurável para respeitar as regras da sua gráfica.</p></div><span class="roadmap-step">ETAPA 04</span></section>
<div class="module-grid">@foreach($modules as $module)@php($available = $module['status'] === 'Disponível')<article @class(['card','module-card','module-available' => $available])><div class="module-card-top"><span class="module-icon"><x-icon :name="$module['icon']"/></span><span @class(['badge','module-badge-ready' => $available])>{{ $module['status'] }}</span></div><h2>{{ $module['name'] }}</h2><p>{{ $module['description'] }}</p>@if($available)<a class="text-link" href="{{ $module['name'] === 'Clientes' ? route('customers.index') : ($module['name'] === 'Catálogo' ? route('products.index') : route('finance.index')) }}">Abrir módulo<x-icon name="arrow"/></a>@else<span class="module-planned">Previsto no roteiro</span>@endif</article>@endforeach</div>
<p class="roadmap-footnote">O roteiro pode evoluir conforme o uso real da equipe. Integrações e regras comerciais serão definidas antes de automatizar cálculos ou comunicações.</p>
@endsection
