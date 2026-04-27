<?php

namespace App\Console\Commands;

use App\Jobs\Odoo\FetchOdooInventoryJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncInventory extends Command
{
    protected $signature = 'sync:inventory
                            {--location= : Odoo location ID to filter}
                            {--full : Reset cursor and sync all inventory}
                            {--dry-run : Print quants without syncing}';

    protected $description = 'Sync Odoo inventory → Shopify';

    public function handle(): int
    {
        $locationId = $this->option('location') ? (int) $this->option('location') : null;
        $full       = $this->option('full');
        $dryRun     = $this->option('dry-run');

        $this->info('Starting inventory sync...' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $this->warn('Dry-run mode: use sync:products first to ensure variant mappings exist.');
            return self::SUCCESS;
        }

        if ($full) {
            // Reset cursor
            \App\Models\SyncQueueState::forType('inventory')->update([
                'last_odoo_write_date' => null,
                'is_running'           => false,
            ]);
        }

        FetchOdooInventoryJob::dispatchSync($locationId);

        // Inventory fetch runs synchronously, but pushes are queued on "sync".
        // Give immediate feedback so it doesn't look like "nothing happened".
        try {
            $queued = DB::table('jobs')->where('queue', 'sync')->count();
            $this->line("Queued jobs on 'sync': {$queued}. Run `php artisan queue:work --queue=sync` to process.");
        } catch (\Throwable $e) {
            // Queue table may not exist in some environments; ignore.
        }

        $this->info('Inventory sync job completed.');

        return self::SUCCESS;
    }
}
