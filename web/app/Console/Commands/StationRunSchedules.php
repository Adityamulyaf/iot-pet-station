<?php

namespace App\Console\Commands;

use App\Exceptions\CommandRejected;
use App\Models\StationSchedule;
use App\Services\CommandService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class StationRunSchedules extends Command
{
    protected $signature = 'station:run-schedules';

    protected $description = 'Send feed/water commands for schedules that are due (runs every minute via the scheduler)';

    public function handle(CommandService $commands): int
    {
        $now = CarbonImmutable::now('Asia/Jakarta');
        $today = $now->toDateString();

        $due = StationSchedule::query()->with('station.user')->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('last_run_on')->orWhere('last_run_on', '<', $today))
            ->orderBy('time')->get()
            ->filter(function (StationSchedule $schedule) use ($now): bool {
                $at = $now->setTimeFromTimeString($schedule->time);

                return in_array($now->isoWeekday(), array_map('intval', $schedule->days), true)
                    && $now->greaterThanOrEqualTo($at)
                    && $now->lessThan($at->addMinutes(StationSchedule::RETRY_MINUTES));
            });

        // One command per station per minute; the next due schedule for that station waits for the following run.
        foreach ($due->groupBy('station_id') as $schedules) {
            $schedule = $schedules->first();
            $owner = $schedule->station?->user;
            if (! $owner) {
                $this->finish($schedule, $today, 'skipped', 'Station belum punya pemilik.');

                continue;
            }
            try {
                $commands->create($owner, $schedule->station, $schedule->action, [], 'schedule');
                $this->finish($schedule, $today, 'sent', null);
            } catch (CommandRejected $rejected) {
                $reason = $rejected->errors()['command'][0] ?? 'Perintah ditolak.';
                $lastChance = $now->greaterThanOrEqualTo($now->setTimeFromTimeString($schedule->time)->addMinutes(StationSchedule::RETRY_MINUTES - 1));
                if (! $rejected->retryable || $lastChance) {
                    $this->finish($schedule, $today, 'skipped', $reason);
                }
            }
        }

        return self::SUCCESS;
    }

    private function finish(StationSchedule $schedule, string $today, string $status, ?string $note): void
    {
        $schedule->forceFill(['last_run_on' => $today, 'last_status' => $status, 'last_note' => $note])->save();
    }
}
