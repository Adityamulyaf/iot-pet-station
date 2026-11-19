<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('station:run-schedules')->everyMinute()->withoutOverlapping();
