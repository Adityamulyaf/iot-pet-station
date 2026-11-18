<?php

namespace Tests\Feature;

use App\Livewire\StationApp;
use App\Models\Station;
use App\Models\StationCommand;
use App\Models\User;
use App\Services\StationClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class StationClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function createStation(string $device = 'station-esp32'): string
    {
        Artisan::call('station:create', ['device' => $device, 'name' => 'Station ESP32', '--physical' => true]);
        preg_match('/Kode klaim: ([A-Z0-9]{4}-[A-Z0-9]{4})/', Artisan::output(), $match);

        return $match[1];
    }

    private function claim(string $device, string $code)
    {
        return Livewire::actingAs($this->user)->test(StationApp::class)->set('claimDevice', $device)->set('claimCode', $code)->call('claim');
    }

    public function test_create_command_registers_unowned_station_with_code(): void
    {
        $code = $this->createStation();

        $station = Station::query()->where('device_id', 'station-esp32')->firstOrFail();
        $this->assertNull($station->user_id);
        $this->assertFalse($station->simulated);
        $this->assertNotNull($station->claim_code_hash);
        $this->assertNotSame($code, $station->claim_code_hash);
        $this->artisan('station:create', ['device' => 'station-esp32', 'name' => 'Lagi'])->assertFailed();
    }

    public function test_user_claims_station_with_correct_code(): void
    {
        $code = $this->createStation();

        $station = Station::query()->where('device_id', 'station-esp32')->firstOrFail();
        $this->claim('station-esp32', strtolower(str_replace('-', ' ', $code)))->assertHasNoErrors()->assertRedirect(route('station', [$station, 'dashboard']));

        $station->refresh();
        $this->assertSame($this->user->id, $station->user_id);
        $this->assertNull($station->claim_code_hash);
        $this->assertNotNull($station->claimed_at);
    }

    public function test_wrong_code_unknown_device_and_owned_station_give_same_error(): void
    {
        $code = $this->createStation();
        $owned = Station::factory()->create(['device_id' => 'station-owned']);
        app(StationClaimService::class)->issueCode($owned);

        $this->claim('station-esp32', 'AAAA-AAAA')->assertHasErrors(['claimCode' => 'ID perangkat atau kode klaim salah.']);
        $this->claim('tidak-ada', $code)->assertHasErrors(['claimCode' => 'ID perangkat atau kode klaim salah.']);
        $this->claim('station-owned', $code)->assertHasErrors(['claimCode' => 'ID perangkat atau kode klaim salah.']);
        $this->assertNull(Station::query()->where('device_id', 'station-esp32')->value('user_id'));
        $this->assertNotSame($this->user->id, $owned->fresh()->user_id);
    }

    public function test_code_cannot_be_reused_after_claim(): void
    {
        $code = $this->createStation();
        $this->claim('station-esp32', $code)->assertHasNoErrors();
        $this->user = User::factory()->create();

        $this->claim('station-esp32', $code)->assertHasErrors('claimCode');
    }

    public function test_claim_attempts_are_rate_limited(): void
    {
        $code = $this->createStation();
        for ($i = 0; $i < 5; $i++) {
            $this->claim('station-esp32', 'AAAA-AAAA');
        }

        $this->claim('station-esp32', $code)->assertHasErrors('claimCode');
        $this->assertNull(Station::query()->where('device_id', 'station-esp32')->value('user_id'));
    }

    public function test_owner_releases_station_and_history_is_wiped(): void
    {
        $station = Station::factory()->create(['user_id' => $this->user->id]);
        StationCommand::query()->create(['station_id' => $station->id, 'user_id' => $this->user->id, 'action' => 'feed', 'parameters' => [], 'expires_at' => now()->addMinute()]);

        Livewire::actingAs($this->user)->test(StationApp::class, ['station' => $station, 'section' => 'settings'])
            ->call('askRelease')->assertSet('confirmRelease', true)
            ->call('release')->assertRedirect(route('stations'));

        $this->assertNull($station->fresh()->user_id);
        $this->assertSame(0, StationCommand::query()->where('station_id', $station->id)->count());
        $this->actingAs($this->user)->get(route('station', [$station, 'dashboard']))->assertForbidden();
        $this->artisan('station:code', ['device' => $station->device_id])->assertSuccessful();
    }

    public function test_other_users_cannot_release_or_open_a_station(): void
    {
        $station = Station::factory()->create();

        $this->actingAs($this->user)->get(route('station', [$station, 'settings']))->assertForbidden();
        $this->artisan('station:code', ['device' => $station->device_id])->assertFailed();
    }
}
