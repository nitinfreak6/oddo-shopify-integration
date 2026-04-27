<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Models\WebhookLog;
use Illuminate\Console\Command;

class PruneOldLogs extends Command
{
    protected $signature = 'logs:prune {--days=30 : Delete logs older than this many days}';
    protected $description = 'Delete old sync and webhook log records';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $syncDeleted    = SyncLog::where('created_at', '<', $cutoff)->delete();
        $webhookDeleted = WebhookLog::where('created_at', '<', $cutoff)->where('processed', true)->delete();

        $this->info("Pruned {$syncDeleted} sync log(s) and {$webhookDeleted} webhook log(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
