<ul class="list">
@forelse($commands as $command)
    <li wire:key="command-{{ $command->id }}"><button wire:click="showDetail('{{ $command->id }}')">
        <span class="chip {{ ['feed'=>'food','water'=>'water'][$command->action] ?? 'scenario' }}"><x-icon :name="['feed'=>'food','water'=>'water','scenario'=>'settings'][$command->action] ?? 'clock'"/></span>
        <span class="list-main">{{ $command->actionLabel() }}<small>{{ $command->created_at->timezone('Asia/Jakarta')->translatedFormat('d M, H:i') }}</small></span>
        <span class="pill status-{{ $command->status }}">{{ $command->label() }}</span>
    </button></li>
@empty
    <li class="empty">{{ $statusFilter || $actionFilter || $dateFilter ? 'Tidak ada yang cocok' : 'Belum ada perintah' }}</li>
@endforelse
</ul>
