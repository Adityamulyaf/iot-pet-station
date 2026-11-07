<?php

namespace Tests\Feature;

use App\Livewire\StationApp;
use App\Models\DeviceMessage;
use App\Models\Station;
use App\Models\StationCommand;
use App\Models\User;
use App\Services\CommandService;
use App\Services\MessageIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->owner = User::factory()->create();
        $this->station = Station::factory()->create(['user_id' => $this->owner->id, 'connected' => true, 'last_seen_at' => now(), 'telemetry_at' => now(), 'state_at' => now(), 'snapshot' => ['food_g' => 800, 'water_pct' => 80, 'food_valid' => true, 'water_valid' => true, 'pir' => false], 'actuators' => ['servo' => 'closed', 'pump' => 'off']]);
        Cache::put('mqtt-worker-online', true, 60);
    }

    private function message(string $kind, array $fields = []): array
    {
        $payload = ['schema_version' => 1, 'device_id' => $this->station->device_id, 'message_id' => (string) Str::uuid(), 'recorded_at' => now()->toIso8601String()];
        if ($kind === 'telemetry') {
            $payload += ['food_g' => 780, 'water_pct' => 76, 'food_raw' => 100000, 'water_raw' => 3000, 'food_valid' => true, 'water_valid' => true, 'pir' => false, 'servo' => 'closed', 'pump' => 'off'];
        }

        return array_replace($payload, $fields);
    }

    private function ingest(string $kind, array $payload): ?array
    {
        return app(MessageIngestor::class)->ingest('petstation/'.$this->station->device_id.'/'.$kind, json_encode($payload));
    }

    public function test_guests_are_redirected_and_owner_can_open_all_pages(): void
    {
        $this->get('/stations')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Selamat datang kembali');
        $this->actingAs($this->owner)->get('/stations')->assertOk()->assertSee($this->station->name);
        foreach (['dashboard', 'history', 'analytics', 'settings'] as $section) {
            $this->get('/stations/'.$this->station->id.'/'.$section)->assertOk();
        }
    }

    public function test_login_regenerates_authenticated_session_and_logout_revokes_access(): void
    {
        $this->owner->update(['password' => 'petstation-local']);
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'petstation-local'])->assertRedirect('/stations');
        $this->assertAuthenticatedAs($this->owner);
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/stations')->assertRedirect('/login');
    }

    public function test_other_users_cannot_read_or_control_a_station(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->get('/stations/'.$this->station->id.'/dashboard')->assertForbidden();
        Livewire::actingAs($other)->test(StationApp::class)->call('ask', 'feed')->assertNotFound();
        $this->expectException(HttpException::class);
        app(CommandService::class)->create($other, $this->station, 'feed');
    }

    public function test_duplicate_and_out_of_order_telemetry_preserve_latest_snapshot(): void
    {
        $this->travel(1)->seconds();
        $payload = $this->message('telemetry');
        $ack = $this->ingest('telemetry', $payload);
        $this->assertSame($payload['message_id'], $ack['message_id']);
        $this->ingest('telemetry', $payload);
        $old = $this->message('telemetry', ['recorded_at' => now()->subMinutes(2)->toIso8601String(), 'food_g' => 950]);
        $this->ingest('telemetry', $old);
        $this->assertSame(2, DeviceMessage::query()->count());
        $this->assertEquals(780, $this->station->fresh()->snapshot['food_g']);
    }

    public function test_offline_invalid_low_water_and_unavailable_worker_reject_commands(): void
    {
        $checks = [
            ['connected' => false],
            ['connected' => true, 'telemetry_at' => now()->subMinute()],
            ['telemetry_at' => now(), 'snapshot' => ['food_g' => 800, 'water_pct' => 80, 'food_valid' => true, 'water_valid' => false]],
            ['snapshot' => ['food_g' => 800, 'water_pct' => 8, 'food_valid' => true, 'water_valid' => true]],
        ];
        foreach ($checks as $changes) {
            $this->station->update($changes);
            try {
                app(CommandService::class)->create($this->owner, $this->station, 'water');
                $this->fail('Unsafe request accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('command', $exception->errors());
            }
        }
        Cache::forget('mqtt-worker-online');
        $this->expectException(ValidationException::class);
        app(CommandService::class)->create($this->owner, $this->station, 'feed');
    }

    public function test_timeout_stays_locked_until_late_result_and_cannot_regress(): void
    {
        $command = app(CommandService::class)->create($this->owner, $this->station, 'feed');
        $command->update(['published_at' => now()]);
        $this->travel(1)->seconds();
        $this->ingest('result', $this->message('result', ['command_id' => $command->id, 'status' => 'running']));
        $this->travel(31)->seconds();
        app(CommandService::class)->expire();
        $this->assertSame('timeout', $command->fresh()->status);
        $this->assertNull($command->fresh()->resolved_at);
        try {
            app(CommandService::class)->create($this->owner, $this->station, 'feed');
            $this->fail('Unresolved timeout unlocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('belum terkonfirmasi', $exception->getMessage());
        }
        $this->ingest('result', $this->message('result', ['command_id' => $command->id, 'status' => 'completed', 'duration_ms' => 2000, 'before' => ['food_g' => 800], 'after' => ['food_g' => 775]]));
        $this->assertSame('completed', $command->fresh()->status);
        $this->assertNotNull($command->fresh()->timed_out_at);
        $this->assertNotNull($command->fresh()->resolved_at);
        $this->travel(1)->seconds();
        $this->ingest('result', $this->message('result', ['command_id' => $command->id, 'status' => 'running']));
        $this->assertSame('completed', $command->fresh()->status);
    }

    public function test_second_command_is_blocked_and_device_rejection_is_recorded(): void
    {
        $command = app(CommandService::class)->create($this->owner, $this->station, 'water');
        $this->assertSame(2000, $command->parameters['duration_ms']);
        try {
            app(CommandService::class)->create($this->owner, $this->station, 'feed');
            $this->fail('Concurrent command accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('command', $exception->errors());
        }
        $this->travel(1)->seconds();
        $this->ingest('result', $this->message('result', ['command_id' => $command->id, 'status' => 'rejected', 'reason' => 'Cooldown belum selesai.']));
        $this->assertSame('rejected', $command->fresh()->status);
        $this->assertSame('Cooldown belum selesai.', $command->fresh()->reason);
    }

    public function test_retained_old_online_state_does_not_make_station_online(): void
    {
        $this->station->update(['connected' => false, 'last_seen_at' => now()->subMinute(), 'state_at' => null]);
        $payload = $this->message('state', ['recorded_at' => now()->subMinutes(2)->toIso8601String(), 'online' => true, 'servo' => 'closed', 'pump' => 'off']);
        app(MessageIngestor::class)->ingest('petstation/'.$this->station->device_id.'/state', json_encode($payload), true);
        $this->assertFalse($this->station->fresh()->online());
    }

    public function test_topic_identity_mismatch_is_not_stored(): void
    {
        $this->expectException(ValidationException::class);
        $this->ingest('telemetry', $this->message('telemetry', ['device_id' => 'different-station']));
    }

    public function test_unpublished_expired_command_is_failed_without_an_unknown_actuator_outcome(): void
    {
        $command = app(CommandService::class)->create($this->owner, $this->station, 'feed');
        $this->travel(16)->seconds();
        app(CommandService::class)->expire();
        $this->assertSame('failed', $command->fresh()->status);
        $this->assertNotNull($command->fresh()->resolved_at);
        $this->assertNull($command->fresh()->timed_out_at);
    }

    public function test_completed_feed_without_weight_change_requires_inspection(): void
    {
        $command = app(CommandService::class)->create($this->owner, $this->station, 'feed');
        $this->travel(1)->seconds();
        $this->ingest('result', $this->message('result', ['command_id' => $command->id, 'status' => 'completed', 'before' => ['food_valid' => true, 'food_g' => 800], 'after' => ['food_valid' => true, 'food_g' => 800]]));
        $this->assertStringContainsString('Periksa penyaluran', $command->fresh()->reason);
        $this->assertSame('completed', $command->fresh()->status);
    }

    public function test_analytics_averages_valid_sensor_values_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-07T20:22:00Z'));
        $this->ingest('telemetry', $this->message('telemetry', ['food_g' => 100, 'water_pct' => 50]));
        $this->ingest('telemetry', $this->message('telemetry', ['food_g' => 900, 'food_valid' => false, 'water_pct' => 70]));
        Livewire::actingAs($this->owner)->test(StationApp::class, ['station' => $this->station, 'section' => 'analytics'])
            ->assertViewHas('chart', fn (array $chart): bool => $chart['food'] === [100.0] && $chart['water'] === [60.0] && $chart['labels'] === ['03:15']);
    }

    public function test_livewire_confirmation_and_history_are_scoped_to_owner(): void
    {
        $component = Livewire::actingAs($this->owner)->test(StationApp::class, ['station' => $this->station, 'section' => 'dashboard'])
            ->call('ask', 'feed')->assertSet('confirmation', 'feed')->call('send')->assertSet('confirmation', null);
        $this->assertDatabaseCount('station_commands', 1);
        $other = Station::factory()->create();
        $command = StationCommand::query()->create(['station_id' => $other->id, 'user_id' => $other->user_id, 'action' => 'feed', 'parameters' => [], 'expires_at' => now()->addMinute()]);
        $component->call('showDetail', $command->id)->assertNotFound();
    }
}
