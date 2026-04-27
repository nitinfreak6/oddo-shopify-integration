<?php

namespace App\Console\Commands;

use App\Jobs\Odoo\FetchOdooInventoryJob;
use Illuminate\Console\Command;

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

        $this->info('Inventory sync job completed.');

        return self::SUCCESS;
    }
}
