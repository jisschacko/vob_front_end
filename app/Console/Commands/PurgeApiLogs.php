<?php

namespace App\Console\Commands;

use App\Models\ApiLog;
use Illuminate\Console\Command;

class PurgeApiLogs extends Command
{
    protected $signature = 'logs:purge-api {--months= : Keep this many months (default API_LOG_RETENTION_MONTHS, 2)} {--chunk=1000 : Rows deleted per query}';

    protected $description = 'Delete api_logs rows older than the retention period';

    public function handle(): int
    {
        $months = (int) ($this->option('months') ?? config('admin_api.log_retention_months'));
        if ($months < 1) {
            $this->error('--months must be at least 1.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = now()->subMonths($months);
        $deleted = 0;

        do {
            $count = ApiLog::where('created_at', '<', $cutoff)->limit($chunk)->delete();
            $deleted += $count;
        } while ($count === $chunk);

        $this->info("Purged {$deleted} api_logs rows older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
