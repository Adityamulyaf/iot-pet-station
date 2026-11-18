@if($paginator->hasPages())
<nav class="pager" aria-label="Halaman">
    <button class="button soft" wire:click="previousPage" @disabled($paginator->onFirstPage())>Sebelumnya</button>
    <span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
    <button class="button soft" wire:click="nextPage" @disabled(!$paginator->hasMorePages())>Berikutnya</button>
</nav>
@endif
