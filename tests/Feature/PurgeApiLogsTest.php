<?php

use App\Models\ApiLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function logRow(string $createdAt): void
{
    ApiLog::create(['service' => 'admin_api', 'method' => 'GET', 'url' => 'https://admin.test/api/v1/masters', 'created_at' => $createdAt]);
}

it('deletes rows older than two months by default and keeps newer ones', function () {
    logRow(now()->subMonths(2)->subDay());
    logRow(now()->subMonths(5));
    logRow(now()->subMonths(2)->addDay());
    logRow(now());

    $this->artisan('logs:purge-api')->assertSuccessful();

    expect(ApiLog::count())->toBe(2);
});

it('honours --months and deletes in chunks', function () {
    foreach (range(1, 7) as $i) {
        logRow(now()->subMonths(4));
    }
    logRow(now()->subDays(10));

    $this->artisan('logs:purge-api --months=3 --chunk=3')->assertSuccessful();
    expect(ApiLog::count())->toBe(1);
});

it('rejects a retention below one month', function () {
    logRow(now()->subYear());

    $this->artisan('logs:purge-api --months=0')->assertFailed();
    expect(ApiLog::count())->toBe(1);
});

it('is scheduled daily', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'logs:purge-api'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->command)->toContain('--months=2');
});
