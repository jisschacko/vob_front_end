<?php

use Illuminate\Support\Facades\Schedule;

// Needs the Laravel scheduler cron entry: * * * * * cd /path/to/vob && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('logs:purge-api --months=2')->daily()->withoutOverlapping();
