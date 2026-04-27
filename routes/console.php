<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Sync Commands
|--------------------------------------------------------------------------
*/
// Shopify sync
Schedule::command('sync:inventory')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('sync:products')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('sync:orders')->hourly()->withoutOverlapping()->runInBackground();
Schedule::command('sync:customers')->dailyAt('02:00')->withoutOverlapping()->runInBackground();

// Amazon sync
Schedule::command('sync:amazon-orders')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();

// Maintenance
Schedule::command('logs:prune --days=30')->weekly();
