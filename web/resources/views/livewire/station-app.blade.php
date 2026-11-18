<div class="app" wire:poll.5s>
    @php
        $sections = ['dashboard' => 'Dashboard', 'history' => 'Riwayat', 'analytics' => 'Grafik', 'settings' => 'Pengaturan'];
    @endphp
    <header class="top">
        <div class="top-row">
            <a href="{{ route('stations') }}" class="brand"><x-icon name="food"/><span>petstation</span></a>
            @if($station)
                <div class="picker"><select aria-label="Pilih station" onchange="window.location.href=this.value">@foreach($stations as $item)<option value="{{ route('station',[$item,$section]) }}" @selected($item->id === $station->id)>{{ $item->name }}</option>@endforeach</select></div>
            @else
                <span class="picker"></span>
            @endif
            <form action="{{ route('logout') }}" method="POST">@csrf<button class="icon-button" title="Keluar" aria-label="Keluar"><x-icon name="logout"/></button></form>
        </div>
        @if($station)
            <nav class="tabs" aria-label="Menu station">
                @foreach($sections as $key => $label)
                    <a href="{{ route('station',[$station,$key]) }}" class="{{ $section === $key ? 'active' : '' }}" @if($section === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        @endif
    </header>

    <main id="main-content" class="main">
        @if($notice)<div class="toast" role="status"><x-icon name="check"/>{{ $notice }}<button class="icon-button" wire:click="$set('notice','')" aria-label="Tutup"><x-icon name="close"/></button></div>@endif
        @error('command')<div class="alert" role="alert">{{ $message }}</div>@enderror

        @if(!$station)
            <h1>Station saya</h1>
            <div class="stations">
                @foreach($stations as $item)
                    @php
                        $snap = $item->snapshot ?? [];
                        $itemFood = ($snap['food_valid'] ?? false) ? number_format($snap['food_g'], 0, ',', '.').' g' : '–';
                        $itemWater = ($snap['water_valid'] ?? false) ? round($snap['water_pct']).'%' : '–';
                    @endphp
                    <a class="station-card" href="{{ route('station',[$item,'dashboard']) }}">
                        <div class="station-card-top"><h2>{{ $item->name }}</h2><span class="pill {{ $item->online() ? 'on' : 'off' }}">{{ $item->online() ? 'Online' : 'Offline' }}</span></div>
                        @if($item->user_id !== auth()->id())<span class="pill shared">Dibagikan</span>@endif
                        <div class="station-card-values"><div>Pakan<strong>{{ $itemFood }}</strong></div><div>Air<strong>{{ $itemWater }}</strong></div></div>
                    </a>
                @endforeach
                <section class="station-card add-card">
                    <h2>Tambah station</h2>
                    <form wire:submit="claim" class="form">
                        <label for="claim-device">ID perangkat</label>
                        <input id="claim-device" wire:model="claimDevice" maxlength="80" autocomplete="off" placeholder="station-esp32">
                        <label for="claim-code">Kode klaim</label>
                        <input id="claim-code" wire:model="claimCode" maxlength="20" autocomplete="off" autocapitalize="characters" placeholder="XXXX-XXXX">
                        @if($errors->hasAny(['claimDevice', 'claimCode']))<p class="form-error" role="alert">{{ $errors->first('claimDevice') ?: $errors->first('claimCode') }}</p>@endif
                        <button class="button" type="submit" wire:loading.attr="disabled">Tambah</button>
                    </form>
                </section>
            </div>
        @else
            @php
                $data = $station->snapshot ?? [];
                $fresh = $station->hasFreshTelemetry();
                $foodValid = $fresh && ($data['food_valid'] ?? false);
                $waterValid = $fresh && ($data['water_valid'] ?? false);
                $food = $data['food_g'] ?? 0;
                $water = $data['water_pct'] ?? 0;
                $foodFull = config('station.bowl_food_full_g');
                $waterFull = config('station.bowl_water_full_pct');
                $serviceOnline = (bool) \Illuminate\Support\Facades\Cache::get('mqtt-worker-online');
                $ready = $station->online() && $fresh && $foodValid && $waterValid && !$busy && !$cooldown && $serviceOnline && ($station->actuators['servo'] ?? '') === 'closed' && ($station->actuators['pump'] ?? '') === 'off';
                $hint = match (true) {
                    $busy => 'Menunggu perintah sebelumnya selesai…',
                    $cooldown => 'Tunggu 10 detik sebelum perintah berikutnya.',
                    !$serviceOnline => 'Server perangkat terputus.',
                    !$station->online() => 'Station offline.',
                    !$fresh => 'Menunggu data terbaru.',
                    !$foodValid || !$waterValid => 'Sensor tidak terbaca.',
                    default => '',
                };
                $canOperate = in_array($role, [\App\Models\Station::ROLE_OWNER, \App\Models\Station::ROLE_OPERATOR], true);
                $isOwner = $role === \App\Models\Station::ROLE_OWNER;
                $bowlNote = fn (bool $valid, float $value, float $full) => !$valid ? 'Tidak terbaca' : ($value >= $full ? 'Penuh' : ($value < 20 ? 'Hampir kosong' : ''));
            @endphp

            <div class="status-row">
                <span class="pill {{ $station->online() ? 'on' : 'off' }}">{{ $station->online() ? 'Online' : 'Offline' }}</span>
                @if($station->simulated)<span class="pill">Simulasi</span>@endif
                @unless($isOwner)<span class="pill shared">{{ \App\Models\Station::SHARED_ROLES[$role] }}</span>@endunless
                @if($fresh && ($data['pir'] ?? false))<span class="pill warn">Ada gerakan</span>@endif
                <span class="time">@if($station->telemetry_at){{ $fresh ? '' : 'Terlambat · ' }}{{ $station->telemetry_at->timezone('Asia/Jakarta')->format('H:i:s') }}@else Belum ada data @endif</span>
            </div>

            @if($section === 'dashboard')
                <div class="tiles">
                    <section class="tile food">
                        <div class="tile-head"><x-icon name="food"/>Pakan</div>
                        <div class="tile-value">{{ isset($data['food_g']) ? number_format($food,0,',','.') : '–' }}<small>g</small></div>
                        <div class="bar"><span style="width:{{ min(100,max(0,$food/$foodFull*100)) }}%"></span></div>
                        <div class="tile-note">{{ $bowlNote($foodValid, $food, $foodFull) }}</div>
                        @if($canOperate)<button class="button wide" wire:click="ask('feed')" @disabled(!$ready || $food >= $foodFull)>Beri pakan</button>@endif
                    </section>
                    <section class="tile water">
                        <div class="tile-head"><x-icon name="water"/>Air</div>
                        <div class="tile-value">{{ isset($data['water_pct']) ? round($water) : '–' }}<small>%</small></div>
                        <div class="bar"><span style="width:{{ min(100,max(0,$water)) }}%"></span></div>
                        <div class="tile-note">{{ $bowlNote($waterValid, $water, $waterFull) }}</div>
                        @if($canOperate)<button class="button wide" wire:click="ask('water')" @disabled(!$ready || $water >= $waterFull)>Salurkan air</button>@endif
                    </section>
                </div>
                @if($canOperate && $hint)<p class="hint">{{ $hint }}</p>@endif

                <section class="panel">
                    <div class="panel-head"><h2>Aktivitas</h2><a href="{{ route('station',[$station,'history']) }}">Semua</a></div>
                    @include('partials.commands')
                </section>
            @elseif($section === 'history')
                <section class="panel">
                    <div class="filters">
                        <div><label for="status-filter">Status</label><select id="status-filter" wire:model.live="statusFilter"><option value="">Semua</option>@foreach(\App\Models\StationCommand::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                        <div><label for="action-filter">Tindakan</label><select id="action-filter" wire:model.live="actionFilter"><option value="">Semua</option><option value="feed">Beri pakan</option><option value="water">Salurkan air</option><option value="scenario">Ubah skenario</option></select></div>
                        <div><label for="date-filter">Tanggal</label><input id="date-filter" type="date" wire:model.live="dateFilter"></div>
                        @if($statusFilter || $actionFilter || $dateFilter)<button class="button soft" wire:click="$set('statusFilter',''); $set('actionFilter',''); $set('dateFilter','')">Reset</button>@endif
                    </div>
                    @include('partials.commands')
                    {{ $commands->links('partials.pagination') }}
                </section>
            @elseif($section === 'analytics')
                <div class="stats">
                    @foreach(['total' => 'Perintah', 'completed' => 'Selesai', 'unconfirmed' => 'Belum terkonfirmasi', 'motion' => 'Gerakan terdeteksi'] as $key => $label)
                        <div class="stat"><strong>{{ $stats[$key] }}</strong>{{ $label }}</div>
                    @endforeach
                </div>
                <section class="panel">
                    <div class="panel-head"><h2>Isi wadah</h2>@include('partials.range')</div>
                    @include('partials.chart')
                </section>
            @elseif(!$isOwner)
                <div class="settings">
                    <section class="panel">
                        <h2 class="panel-head">Akses kamu</h2>
                        <div class="facts"><span class="pill shared">{{ \App\Models\Station::SHARED_ROLES[$role] }}</span><span class="pill">Dari {{ $station->user?->name }}</span></div>
                        <p class="muted small">{{ $role === \App\Models\Station::ROLE_OPERATOR ? 'Kamu bisa memantau dan memberi pakan atau air.' : 'Kamu bisa memantau, tapi tidak mengirim perintah.' }}</p>
                        <button class="button danger" wire:click="leave" wire:confirm="Keluar dari {{ $station->name }}?">Keluar dari station</button>
                    </section>
                </div>
            @else
                <div class="settings">
                    <section class="panel">
                        <h2 class="panel-head">Station</h2>
                        <form wire:submit="saveStation" class="form">
                            <label for="station-name">Nama</label>
                            <input id="station-name" wire:model="stationName" maxlength="80" required>
                            @error('stationName')<p class="form-error">{{ $message }}</p>@enderror
                            <label for="station-location">Lokasi</label>
                            <input id="station-location" wire:model="stationLocation" maxlength="80" required>
                            @error('stationLocation')<p class="form-error">{{ $message }}</p>@enderror
                            <div class="facts"><span class="pill">{{ $station->device_id }}</span><span class="pill">{{ $station->simulated ? 'Simulator' : 'Perangkat fisik' }}</span></div>
                            <button class="button" type="submit" wire:loading.attr="disabled">Simpan</button>
                        </form>
                    </section>
                    <section class="panel">
                        <h2 class="panel-head">Bagikan</h2>
                        <form wire:submit="share" class="form">
                            <label for="share-email">Email akun</label>
                            <input id="share-email" type="email" wire:model="shareEmail" autocomplete="off" placeholder="nama@email.com">
                            <div class="segmented" role="radiogroup" aria-label="Peran">
                                @foreach(\App\Models\Station::SHARED_ROLES as $value => $label)
                                    <label><input type="radio" wire:model="shareRole" value="{{ $value }}"><span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                            @if($errors->hasAny(['shareEmail', 'shareRole']))<p class="form-error" role="alert">{{ $errors->first('shareEmail') ?: $errors->first('shareRole') }}</p>@endif
                            <button class="button" type="submit" wire:loading.attr="disabled">Bagikan</button>
                        </form>
                        @if($members->isNotEmpty())
                            <ul class="members">
                                @foreach($members as $member)
                                    <li wire:key="member-{{ $member->id }}"><span class="list-main">{{ $member->name }}<small>{{ $member->email }}</small></span><span class="pill shared">{{ \App\Models\Station::SHARED_ROLES[$member->pivot->role] ?? $member->pivot->role }}</span><button class="icon-button" wire:click="revoke({{ $member->id }})" wire:confirm="Cabut akses {{ $member->name }}?" aria-label="Cabut akses {{ $member->name }}"><x-icon name="close"/></button></li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                    <section class="panel">
                        <h2 class="panel-head">Lepas station</h2>
                        <p class="muted small">Station keluar dari akun ini dan riwayatnya dihapus.</p>
                        <button class="button danger" wire:click="askRelease">Lepas station</button>
                    </section>
                    @if($station->simulated && app()->environment('local'))
                        <section class="panel">
                            <h2 class="panel-head">Skenario simulator</h2>
                            <form wire:submit="applyScenario" class="form">
                                <label for="scenario">Kondisi</label>
                                <select id="scenario" wire:model="scenario"><option value="normal">Normal</option><option value="low_water">Air rendah</option><option value="invalid_sensor">Sensor tidak valid</option><option value="offline">Offline</option><option value="delayed">Hasil terlambat</option><option value="no_result">Hasil tidak diterima</option><option value="duplicate">Pesan duplikat</option></select>
                                <button class="button soft" wire:loading.attr="disabled" @disabled(!$serviceOnline)>Terapkan</button>
                            </form>
                        </section>
                    @endif
                </div>
            @endif
        @endif
    </main>

    @if($confirmation)
        <div class="modal-overlay" x-data x-init="$nextTick(() => $refs.cancel.focus())" @keydown.escape.window="$wire.cancel()" wire:key="confirm-modal">
            <section class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title" x-trap.inert.noscroll="true">
                <span class="chip {{ $confirmation === 'feed' ? 'food' : 'water' }}"><x-icon :name="$confirmation === 'feed' ? 'food' : 'water'"/></span>
                <h2 id="confirm-title">{{ $confirmation === 'feed' ? 'Beri pakan sekarang?' : 'Salurkan air sekarang?' }}</h2>
                <p class="muted">{{ $station->name }}</p>
                @error('command')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                <div class="modal-actions">
                    <button class="button soft" wire:click="cancel" x-ref="cancel">Batal</button>
                    <button class="button" wire:click="send" wire:loading.attr="disabled"><span wire:loading.remove wire:target="send">{{ $confirmation === 'feed' ? 'Beri pakan' : 'Salurkan air' }}</span><span wire:loading wire:target="send">Mengirim…</span></button>
                </div>
            </section>
        </div>
    @endif

    @if($confirmRelease && $station)
        <div class="modal-overlay" x-data x-init="$nextTick(() => $refs.cancel.focus())" @keydown.escape.window="$wire.cancel()" wire:key="release-modal">
            <section class="modal" role="dialog" aria-modal="true" aria-labelledby="release-title" x-trap.inert.noscroll="true">
                <h2 id="release-title">Lepas {{ $station->name }}?</h2>
                <p class="muted">Riwayat dan data sensor station ini dihapus. Untuk menambahkannya lagi, kamu perlu kode klaim baru dari admin.</p>
                <div class="modal-actions">
                    <button class="button soft" wire:click="cancel" x-ref="cancel">Batal</button>
                    <button class="button danger" wire:click="release" wire:loading.attr="disabled">Lepas station</button>
                </div>
            </section>
        </div>
    @endif

    @if($detail)
        <div class="modal-overlay" x-data x-init="$nextTick(() => $refs.close.focus())" @keydown.escape.window="$wire.cancel()" wire:key="detail-modal">
            <section class="modal" role="dialog" aria-modal="true" aria-labelledby="detail-title" x-trap.inert.noscroll="true">
                <div class="panel-head"><h2 id="detail-title">{{ $detail->actionLabel() }}</h2><button class="icon-button" wire:click="cancel" x-ref="close" aria-label="Tutup"><x-icon name="close"/></button></div>
                <span class="pill status-{{ $detail->status }}">{{ $detail->label() }}</span>
                <dl class="details">
                    @if($detail->user)<div><dt>Oleh</dt><dd>{{ $detail->user->name }}</dd></div>@endif
                    <div><dt>Waktu</dt><dd>{{ $detail->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') }}</dd></div>
                    @if(isset($detail->result['duration_ms']))<div><dt>Durasi</dt><dd>{{ $detail->result['duration_ms'] }} ms</dd></div>@endif
                    @foreach(['before' => 'Sebelum', 'after' => 'Sesudah'] as $key => $label)
                        @if(isset($detail->result[$key]))<div><dt>{{ $label }}</dt><dd>{{ $detail->result[$key]['food_g'] ?? '–' }} g · {{ $detail->result[$key]['water_pct'] ?? '–' }}%</dd></div>@endif
                    @endforeach
                    @if($detail->reason)<div><dt>Catatan</dt><dd>{{ $detail->reason }}</dd></div>@endif
                </dl>
            </section>
        </div>
    @endif
</div>
