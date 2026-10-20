<?php

namespace App\Console\Commands;

use App\Models\CashfreeVerification;
use Illuminate\Console\Command;

class PurgeCashfreeVerifications extends Command
{
    protected $signature = 'cashfree:purge-verifications {--days= : Keep rows this many days after they expire (default CASHFREE_PURGE_AFTER_DAYS, 2)}';

    protected $description = 'Delete expired temporary bank verification rows';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('cashfree.purge_after_days'));
        if ($days < 0) {
            $this->error('--days must be 0 or more.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;
        do {
            $count = CashfreeVerification::where('expires_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $count;
        } while ($count === 1000);

        $this->info("Purged {$deleted} bank verification rows that expired before {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
