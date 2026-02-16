<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('aegis:vacuum')->weekly();
Schedule::command('aegis:memory:decay')->weekly();
Schedule::command('aegis:memory:consolidate')->monthly();
Schedule::command('aegis:proactive:run')->everyMinute();
