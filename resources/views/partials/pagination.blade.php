@if($paginator->hasPages())
{{-- Links nativos preservam a busca e funcionam sem JavaScript. --}}
<nav class="pagination" aria-label="{{ $paginationLabel ?? 'Paginação de clientes' }}"><span class="muted">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span><div>@if($paginator->onFirstPage())<span class="button secondary disabled" aria-disabled="true">Anterior</span>@else<a class="button secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>@endif @if($paginator->hasMorePages())<a class="button secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima</a>@else<span class="button secondary disabled" aria-disabled="true">Próxima</span>@endif</div></nav>
@endif
