<?php

namespace App\Services;

use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StationClaimService
{
    /** Huruf dan angka yang tidak mudah tertukar saat dibaca dari stiker (tanpa 0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 600;

    /**
     * Create a new one-time claim code for a station and return it formatted as XXXX-XXXX.
     */
    public function issueCode(Station $station): string
    {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $station->forceFill(['claim_code_hash' => Hash::make($raw)])->save();

        return substr($raw, 0, 4).'-'.substr($raw, 4);
    }

    /**
     * Assign an unowned station to the user when the device ID and claim code match.
     *
     * Every failure returns the same message so device IDs cannot be probed.
     */
    public function claim(User $user, string $deviceId, string $code, string $ip): Station
    {
        $keys = ['claim-user:'.$user->id, 'claim-ip:'.$ip];
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages(['claimCode' => "Terlalu banyak percobaan. Coba lagi dalam {$minutes} menit."]);
            }
        }

        $station = DB::transaction(function () use ($user, $deviceId, $code): ?Station {
            $station = Station::query()->where('device_id', trim($deviceId))->lockForUpdate()->first();
            $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
            if (! $station || $station->user_id !== null || ! $station->claim_code_hash || ! Hash::check($normalized, $station->claim_code_hash)) {
                return null;
            }
            $station->forceFill(['user_id' => $user->id, 'claim_code_hash' => null, 'claimed_at' => now()])->save();

            return $station;
        });

        if (! $station) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }
            throw ValidationException::withMessages(['claimCode' => 'ID perangkat atau kode klaim salah.']);
        }
        RateLimiter::clear($keys[0]);

        return $station;
    }

    /**
     * Remove the owner and wipe history so the next owner starts clean. A new code must be issued by the admin.
     */
    public function release(Station $station): void
    {
        DB::transaction(function () use ($station): void {
            $station->commands()->delete();
            $station->messages()->delete();
            $station->forceFill(['user_id' => null, 'claim_code_hash' => null, 'claimed_at' => null])->save();
        });
    }
}
