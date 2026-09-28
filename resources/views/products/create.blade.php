@extends('layouts.app')
@section('title', 'Novo produto')
@section('content')
<a class="back-link" href="{{ route('products.index') }}">← Voltar para catálogo</a><div class="page-heading"><div><span class="eyebrow">CATÁLOGO</span><h1>Novo produto</h1><p class="muted">Informações claras para organizar os produtos da sua gráfica.</p></div></div>
<form class="customer-form" method="POST" action="{{ route('products.store') }}">@csrf  @include('products._form')</form>
@endsection
