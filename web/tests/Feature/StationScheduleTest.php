<?php

namespace Tests\Feature;

use App\Livewire\StationApp;
use App\Models\Station;
use App\Models\StationCommand;
use App\Models\StationSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class StationScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        // Kamis 19 Nov 2026, 07:00 WIB.
        $this->travelTo(CarbonImmutable::parse('2026-11-19 07:00:00', 'Asia/Jakarta'));
        $this->owner = User::factory()->create();
        $this->station = Station::factory()->create(['user_id' => $this->owner->id]);
        $this->freshTelemetry(['food_g' => 50, 'water_pct' => 40]);
        Cache::put('mqtt-worker-online', true, 3600);
    }

    private function freshTelemetry(array $snapshot = []): void
    {
        $this->station->update(['connected' => true, 'last_seen_at' => now(), 'telemetry_at' => now(), 'state_at' => now(),
            'snapshot' => array_replace(['food_g' => 50, 'water_pct' => 40, 'food_valid' => true, 'water_valid' => true, 'pir' => false], $snapshot),
            'actuators' => ['servo' => 'closed', 'pump' => 'off']]);
    }

    private function schedule(array $fields = []): StationSchedule
    {
        return $this->station->schedules()->create(array_replace(['action' => 'feed', 'time' => '07:00', 'days' => [1, 2, 3, 4, 5, 6, 7]], $fields));
    }

    public function test_owner_adds_toggles_and_deletes_schedules(): void
    {
        $page = Livewire::actingAs($this->owner)->test(StationApp::class, ['station' => $this->station, 'section' => 'schedule'])
            ->set('scheduleAction', 'water')->set('scheduleTime', '18:30')->set('scheduleDays', ['1', '3', '5'])->call('addSchedule')
            ->assertHasNoErrors()->assertSee('18:30')->assertSee('Sen, Rab, Jum');

        $schedule = StationSchedule::query()->sole();
        $this->assertSame([1, 3, 5], $schedule->days);
        $page->call('toggleSchedule', $schedule->id);
        $this->assertFalse($schedule->fresh()->enabled);
        $page->call('deleteSchedule', $schedule->id);
        $this->assertSame(0, StationSchedule::query()->count());

        $page->set('scheduleDays', [])->call('addSchedule')->assertHasErrors('scheduleDays');
        $page->set('scheduleDays', [1])->set('scheduleTime', '25:00')->call('addSchedule')->assertHasErrors('scheduleTime');
    }

    public function test_members_cannot_change_schedules(): void
    {
        $operator = User::factory()->create();
        $this->station->members()->attach($operator, ['role' => 'operator']);
        $schedule = $this->schedule();

        $page = fn () => Livewire::actingAs($operator)->test(StationApp::class, ['station' => $this->station, 'section' => 'schedule']);
        $page()->assertSee('07:00')->assertDontSee('Simpan jadwal');
        $page()->call('addSchedule')->assertForbidden();
        $page()->call('toggleSchedule', $schedule->id)->assertForbidden();
        $page()->call('deleteSchedule', $schedule->id)->assertForbidden();
        $this->assertTrue($schedule->fresh()->enabled);
    }

    public function test_due_schedule_sends_command_once_per_day(): void
    {
        $schedule = $this->schedule();

        $this->artisan('station:run-schedules')->assertSuccessful();
        $command = StationCommand::query()->sole();
        $this->assertSame('feed', $command->action);
        $this->assertSame('schedule', $command->source);
        $this->assertSame($this->owner->id, $command->user_id);
        $this->assertSame('sent', $schedule->fresh()->last_status);

        $command->update(['status' => 'completed', 'resolved_at' => now()->subMinute()]);
        $this->travel(1)->minutes();
        $this->freshTelemetry();
        $this->artisan('station:run-schedules');
        $this->assertSame(1, StationCommand::query()->count());
    }

    public function test_schedule_respects_time_and_day(): void
    {
        $this->schedule(['time' => '07:05']);
        $this->schedule(['time' => '07:00', 'days' => [1]]);
        $this->schedule(['time' => '06:45']);

        $this->artisan('station:run-schedules');

        $this->assertSame(0, StationCommand::query()->count());
    }

    public function test_full_bowl_is_skipped_with_reason(): void
    {
        $this->freshTelemetry(['food_g' => 250]);
        $schedule = $this->schedule();

        $this->artisan('station:run-schedules');

        $this->assertSame(0, StationCommand::query()->count());
        $schedule->refresh();
        $this->assertSame('skipped', $schedule->last_status);
        $this->assertSame('Wadah pakan sudah penuh.', $schedule->last_note);
    }

    public function test_feed_and_water_at_same_time_run_one_after_another(): void
    {
        $this->schedule(['action' => 'feed']);
        $water = $this->schedule(['action' => 'water']);

        $this->artisan('station:run-schedules');
        $this->assertSame(['feed'], StationCommand::query()->pluck('action')->all());

        // Feed still running: water waits without being marked as run.
        $this->travel(1)->minutes();
        $this->freshTelemetry();
        $this->artisan('station:run-schedules');
        $this->assertNull($water->fresh()->last_run_on);

        StationCommand::query()->update(['status' => 'completed', 'resolved_at' => now()->subSeconds(30)]);
        $this->travel(1)->minutes();
        $this->freshTelemetry();
        $this->artisan('station:run-schedules');
        $this->assertSame(['feed', 'water'], StationCommand::query()->orderBy('created_at')->pluck('action')->all());
        $this->assertSame('sent', $water->fresh()->last_status);
    }

    public function test_busy_station_is_skipped_after_retry_window(): void
    {
        $water = $this->schedule(['action' => 'water']);
        StationCommand::query()->create(['station_id' => $this->station->id, 'user_id' => $this->owner->id, 'action' => 'feed', 'parameters' => [], 'expires_at' => now()->addHour()]);

        $this->travel(StationSchedule::RETRY_MINUTES - 1)->minutes();
        $this->freshTelemetry();
        $this->artisan('station:run-schedules');

        $this->assertSame('skipped', $water->fresh()->last_status);
        $this->assertSame(1, StationCommand::query()->count());
    }
}
