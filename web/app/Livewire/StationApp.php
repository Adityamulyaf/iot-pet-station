<?php

namespace App\Livewire;

use App\Models\Station;
use App\Models\StationCommand;
use App\Models\StationSchedule;
use App\Models\User;
use App\Services\CommandService;
use App\Services\StationClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class StationApp extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $stationId = null;

    #[Locked]
    public string $section = 'dashboard';

    public string $statusFilter = '';

    public string $actionFilter = '';

    public string $dateFilter = '';

    public string $range = '24h';

    public string $stationName = '';

    public string $stationLocation = '';

    public string $scenario = 'normal';

    #[Locked]
    public ?string $confirmation = null;

    #[Locked]
    public ?string $detailId = null;

    public string $notice = '';

    public string $claimDevice = '';

    public string $claimCode = '';

    #[Locked]
    public bool $confirmRelease = false;

    public string $shareEmail = '';

    public string $shareRole = Station::ROLE_VIEWER;

    public string $scheduleAction = 'feed';

    public string $scheduleTime = '07:00';

    /** @var list<int|string> */
    public array $scheduleDays = [1, 2, 3, 4, 5, 6, 7];

    public function mount(?Station $station = null, ?string $section = null): void
    {
        if ($station?->exists) {
            Gate::authorize('view', $station);
            $this->stationId = $station->id;
            $this->section = $section ?? 'dashboard';
            $this->stationName = $station->name;
            $this->stationLocation = $station->location ?? 'Rumah';
            $this->scenario = $station->scenario ?? 'normal';
        }
    }

    /**
     * Load the current station and check the ability on every request, so revoked access applies immediately.
     */
    private function station(string $ability = 'view'): Station
    {
        $station = Station::query()->findOrFail($this->stationId);
        Gate::authorize($ability, $station);

        return $station;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['statusFilter', 'actionFilter', 'dateFilter'], true)) {
            $this->resetPage();
        }
    }

    public function ask(string $action): void
    {
        $this->station('operate');
        abort_unless(in_array($action, ['feed', 'water'], true), 422);
        $this->resetValidation();
        $this->confirmation = $action;
    }

    public function cancel(): void
    {
        $this->confirmation = null;
        $this->detailId = null;
        $this->confirmRelease = false;
    }

    public function claim(StationClaimService $claims): void
    {
        $this->validate(['claimDevice' => 'required|string|max:80', 'claimCode' => 'required|string|max:20'], [
            'claimDevice.required' => 'Isi ID perangkat.',
            'claimCode.required' => 'Isi kode klaim.',
        ]);
        $station = $claims->claim(auth()->user(), $this->claimDevice, $this->claimCode, request()->ip());
        $this->redirectRoute('station', [$station, 'dashboard']);
    }

    public function askRelease(): void
    {
        $this->station('manage');
        $this->confirmRelease = true;
    }

    public function release(StationClaimService $claims): void
    {
        $claims->release($this->station('manage'));
        $this->redirectRoute('stations');
    }

    public function send(CommandService $service): void
    {
        $station = $this->station('operate');
        if (! $this->confirmation) {
            return;
        }
        $service->create(auth()->user(), $station, $this->confirmation);
        $this->confirmation = null;
        $this->notice = 'Perintah dikirim. Menunggu laporan perangkat.';
    }

    public function applyScenario(CommandService $service): void
    {
        $this->validate(['scenario' => ['required', Rule::in(['normal', 'low_water', 'invalid_sensor', 'offline', 'delayed', 'no_result', 'duplicate'])]]);
        $service->create(auth()->user(), $this->station('manage'), 'scenario', ['scenario' => $this->scenario]);
        $this->notice = 'Perubahan skenario dikirim ke simulator.';
    }

    public function saveStation(): void
    {
        $data = $this->validate(['stationName' => 'required|string|max:80', 'stationLocation' => 'required|string|max:80']);
        $this->station('manage')->update(['name' => $data['stationName'], 'location' => $data['stationLocation']]);
        $this->notice = 'Pengaturan station disimpan.';
    }

    public function showDetail(string $id): void
    {
        $this->station()->commands()->findOrFail($id);
        $this->detailId = $id;
    }

    public function share(): void
    {
        $station = $this->station('manage');
        $this->validate([
            'shareEmail' => 'required|email',
            'shareRole' => ['required', Rule::in(array_keys(Station::SHARED_ROLES))],
        ], ['shareEmail.required' => 'Isi email akun.', 'shareEmail.email' => 'Format email tidak valid.']);
        $user = User::query()->where('email', trim($this->shareEmail))->first();
        if (! $user) {
            $this->addError('shareEmail', 'Belum ada akun dengan email ini. Minta dia daftar dulu.');

            return;
        }
        if ($user->id === $station->user_id) {
            $this->addError('shareEmail', 'Itu akun kamu sendiri.');

            return;
        }
        $station->members()->syncWithoutDetaching([$user->id => ['role' => $this->shareRole]]);
        $this->reset('shareEmail');
        $this->notice = "Akses dibagikan ke {$user->name}.";
    }

    public function addSchedule(): void
    {
        $station = $this->station('manage');
        $data = $this->validate([
            'scheduleAction' => ['required', Rule::in(array_keys(StationSchedule::ACTIONS))],
            'scheduleTime' => ['required', 'date_format:H:i'],
            'scheduleDays' => ['required', 'array', 'min:1'],
            'scheduleDays.*' => ['integer', 'between:1,7'],
        ], ['scheduleDays.required' => 'Pilih minimal satu hari.', 'scheduleDays.min' => 'Pilih minimal satu hari.', 'scheduleTime.date_format' => 'Format jam tidak valid.']);
        if ($station->schedules()->count() >= 20) {
            $this->addError('scheduleTime', 'Maksimal 20 jadwal per station.');

            return;
        }
        $days = collect($data['scheduleDays'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();
        $station->schedules()->create(['action' => $data['scheduleAction'], 'time' => $data['scheduleTime'], 'days' => $days]);
        $this->notice = 'Jadwal ditambahkan.';
    }

    public function toggleSchedule(int $scheduleId): void
    {
        $schedule = $this->station('manage')->schedules()->findOrFail($scheduleId);
        $schedule->update(['enabled' => ! $schedule->enabled]);
    }

    public function deleteSchedule(int $scheduleId): void
    {
        $this->station('manage')->schedules()->findOrFail($scheduleId)->delete();
        $this->notice = 'Jadwal dihapus.';
    }

    public function revoke(int $userId): void
    {
        $this->station('manage')->members()->detach($userId);
        $this->notice = 'Akses dicabut.';
    }

    public function leave(): void
    {
        $station = $this->station();
        abort_if($station->roleFor(auth()->user()) === Station::ROLE_OWNER, 403);
        $station->members()->detach(auth()->id());
        $this->redirectRoute('stations');
    }

    private function chart(Station $station): array
    {
        $hours = ['1h' => 1, '24h' => 24, '7d' => 168][$this->range] ?? 24;
        $bucketSeconds = (int) max(60, $hours * 3600 / 96);
        $driver = DB::connection()->getDriverName();
        $epoch = $driver === 'sqlite' ? "CAST(strftime('%s', recorded_at) AS INTEGER)" : "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', recorded_at)";
        $bucket = $driver === 'sqlite' ? "CAST($epoch / $bucketSeconds AS INTEGER) * $bucketSeconds" : "FLOOR($epoch / $bucketSeconds) * $bucketSeconds";
        $foodValid = $driver === 'sqlite' ? "JSON_EXTRACT(payload, '$.food_valid') = 1" : "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.food_valid')) = 'true'";
        $waterValid = $driver === 'sqlite' ? "JSON_EXTRACT(payload, '$.water_valid') = 1" : "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.water_valid')) = 'true'";
        $rows = $station->messages()->where('kind', 'telemetry')->where('recorded_at', '>=', now()->subHours($hours))
            ->selectRaw("$bucket AS bucket, AVG(CASE WHEN $foodValid THEN JSON_EXTRACT(payload, '$.food_g') END) AS food, AVG(CASE WHEN $waterValid THEN JSON_EXTRACT(payload, '$.water_pct') END) AS water")
            ->groupByRaw($bucket)->orderBy('bucket')->get();

        return ['labels' => $rows->map(fn ($row) => CarbonImmutable::createFromTimestampUTC((int) $row->bucket)->timezone('Asia/Jakarta')->format($hours > 24 ? 'd/m H:i' : 'H:i'))->all(),
            'food' => $rows->map(fn ($row) => $row->food === null ? null : round((float) $row->food, 1))->all(),
            'water' => $rows->map(fn ($row) => $row->water === null ? null : round((float) $row->water, 1))->all()];
    }

    public function render(): View
    {
        app(CommandService::class)->expire();
        $stations = Station::query()->visibleTo(auth()->user())->orderBy('id')->get();
        $station = $this->stationId ? $this->station() : null;
        $role = $station?->roleFor(auth()->user());
        $members = $role === Station::ROLE_OWNER ? $station->members()->orderBy('name')->get() : collect();
        $schedules = $station && $this->section === 'schedule' ? $station->schedules()->orderBy('time')->orderBy('action')->get() : collect();
        $commands = null;
        $chart = ['labels' => [], 'food' => [], 'water' => []];
        $detail = null;
        $stats = [];
        $busy = false;
        $cooldown = false;
        if ($station) {
            $query = $station->commands()->latest();
            if (in_array($this->statusFilter, array_keys(StationCommand::LABELS), true)) {
                $query->where('status', $this->statusFilter);
            }
            if (in_array($this->actionFilter, ['feed', 'water', 'scenario'], true)) {
                $query->where('action', $this->actionFilter);
            }
            if ($this->dateFilter && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->dateFilter)) {
                $start = CarbonImmutable::parse($this->dateFilter, 'Asia/Jakarta')->utc();
                $query->whereBetween('created_at', [$start, $start->addDay()->subSecond()]);
            }
            $commands = $this->section === 'history' ? $query->paginate(12) : $query->limit(5)->get();
            $busy = $station->commands()->whereIn('status', ['pending', 'running', 'timeout'])->whereNull('resolved_at')->exists();
            $cooldown = $station->commands()->whereIn('action', ['feed', 'water'])->where('status', 'completed')->where('resolved_at', '>', now()->subSeconds(10))->exists();
            if ($this->section === 'analytics') {
                $chart = $this->chart($station);
                $this->dispatch('reservoir-chart', chart: $chart);
            }
            $hours = ['1h' => 1, '24h' => 24, '7d' => 168][$this->range] ?? 24;
            $summary = $station->commands()->whereIn('action', ['feed', 'water'])->where('created_at', '>=', now()->subHours($hours));
            $stats = ['total' => (clone $summary)->count(), 'completed' => (clone $summary)->where('status', 'completed')->count(), 'unconfirmed' => (clone $summary)->whereIn('status', ['pending', 'running', 'timeout'])->count(), 'motion' => $station->messages()->where('kind', 'telemetry')->where('recorded_at', '>=', now()->subHours($hours))->where('payload->pir', true)->count()];
            $detail = $this->detailId ? $station->commands()->findOrFail($this->detailId) : null;
        }

        return view('livewire.station-app', compact('stations', 'station', 'role', 'members', 'schedules', 'commands', 'chart', 'detail', 'stats', 'busy', 'cooldown'))
            ->layout('components.layouts.app', ['title' => ['dashboard' => 'Dashboard', 'history' => 'Riwayat', 'schedule' => 'Jadwal', 'analytics' => 'Grafik', 'settings' => 'Pengaturan'][$this->section] ?? 'Station']);
    }
}
