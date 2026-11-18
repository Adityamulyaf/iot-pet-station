<?php

namespace Tests\Feature;

use App\Livewire\StationApp;
use App\Models\Station;
use App\Models\StationCommand;
use App\Models\User;
use App\Services\StationClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class StationSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $guest;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Pemilik']);
        $this->guest = User::factory()->create(['name' => 'Dosen', 'email' => 'dosen@example.com']);
        $this->station = Station::factory()->create(['user_id' => $this->owner->id, 'connected' => true, 'last_seen_at' => now(), 'telemetry_at' => now(), 'state_at' => now(), 'snapshot' => ['food_g' => 50, 'water_pct' => 40, 'food_valid' => true, 'water_valid' => true, 'pir' => false], 'actuators' => ['servo' => 'closed', 'pump' => 'off']]);
        Cache::put('mqtt-worker-online', true, 60);
    }

    private function ownerSettings()
    {
        return Livewire::actingAs($this->owner)->test(StationApp::class, ['station' => $this->station, 'section' => 'settings']);
    }

    private function as(User $user, string $section = 'dashboard')
    {
        return Livewire::actingAs($user)->test(StationApp::class, ['station' => $this->station, 'section' => $section]);
    }

    public function test_owner_shares_by_email_and_member_sees_station(): void
    {
        $this->ownerSettings()->set('shareEmail', 'dosen@example.com')->set('shareRole', 'viewer')->call('share')
            ->assertHasNoErrors()->assertSee('Dosen');

        $this->assertSame(Station::ROLE_VIEWER, $this->station->roleFor($this->guest));
        $this->actingAs($this->guest)->get(route('stations'))->assertOk()->assertSee($this->station->name)->assertSee('Dibagikan');
        foreach (['dashboard', 'history', 'analytics', 'settings'] as $section) {
            $this->get(route('station', [$this->station, $section]))->assertOk();
        }
    }

    public function test_share_rejects_unknown_email_and_own_account(): void
    {
        $this->ownerSettings()->set('shareEmail', 'belum@example.com')->call('share')->assertHasErrors('shareEmail');
        $this->ownerSettings()->set('shareEmail', $this->owner->email)->call('share')->assertHasErrors('shareEmail');
        $this->ownerSettings()->set('shareEmail', 'dosen@example.com')->set('shareRole', 'owner')->call('share')->assertHasErrors('shareRole');
        $this->assertSame(0, $this->station->members()->count());
    }

    public function test_viewer_cannot_send_commands_or_manage(): void
    {
        $this->station->members()->attach($this->guest, ['role' => 'viewer']);

        $this->as($this->guest)->assertDontSee('Beri pakan')->call('ask', 'feed')->assertForbidden();
        $this->as($this->guest, 'settings')->set('stationName', 'Diganti')->call('saveStation')->assertForbidden();
        $this->as($this->guest, 'settings')->set('shareEmail', 'dosen@example.com')->call('share')->assertForbidden();
        $this->as($this->guest, 'settings')->call('askRelease')->assertForbidden();
        $this->assertSame(0, StationCommand::query()->count());
        $this->assertNotSame('Diganti', $this->station->fresh()->name);
    }

    public function test_operator_can_send_commands_but_not_manage(): void
    {
        $this->station->members()->attach($this->guest, ['role' => 'operator']);

        $this->as($this->guest)->assertSee('Beri pakan')->call('ask', 'feed')->call('send')->assertHasNoErrors();
        $command = StationCommand::query()->sole();
        $this->assertSame($this->guest->id, $command->user_id);
        $this->as($this->guest, 'settings')->call('applyScenario')->assertForbidden();
    }

    public function test_revoked_access_applies_immediately(): void
    {
        $this->station->members()->attach($this->guest, ['role' => 'operator']);
        $session = $this->as($this->guest);

        $this->ownerSettings()->call('revoke', $this->guest->id);

        $this->actingAs($this->guest);
        $session->call('ask', 'feed')->assertForbidden();
        $this->actingAs($this->guest)->get(route('station', [$this->station, 'dashboard']))->assertForbidden();
        $this->get(route('stations'))->assertDontSee($this->station->name);
    }

    public function test_member_can_leave_and_release_removes_members(): void
    {
        $this->station->members()->attach($this->guest, ['role' => 'viewer']);
        $this->as($this->guest, 'settings')->call('leave')->assertRedirect(route('stations'));
        $this->assertNull($this->station->roleFor($this->guest));
        $this->as($this->owner, 'settings')->call('leave')->assertForbidden();

        $this->station->members()->attach($this->guest, ['role' => 'viewer']);
        app(StationClaimService::class)->release($this->station);
        $this->assertSame(0, $this->station->members()->count());
    }

    public function test_unrelated_user_is_still_denied(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('station', [$this->station, 'dashboard']))->assertForbidden();
        $this->get(route('stations'))->assertDontSee($this->station->name);
    }
}
