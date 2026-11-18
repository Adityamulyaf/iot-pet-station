<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;

class StationPolicy
{
    /**
     * Owner and every shared member can see the dashboard, history, and charts.
     */
    public function view(User $user, Station $station): bool
    {
        return $station->roleFor($user) !== null;
    }

    /**
     * Owner and operators can send feed and water commands.
     */
    public function operate(User $user, Station $station): bool
    {
        return in_array($station->roleFor($user), [Station::ROLE_OWNER, Station::ROLE_OPERATOR], true);
    }

    /**
     * Only the owner can rename, share, run simulator scenarios, or release the station.
     */
    public function manage(User $user, Station $station): bool
    {
        return $station->roleFor($user) === Station::ROLE_OWNER;
    }
}
