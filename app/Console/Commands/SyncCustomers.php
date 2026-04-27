<?php

namespace App\Console\Commands;

use App\Jobs\Odoo\FetchOdooCustomersJob;
use Illuminate\Console\Command;

class SyncCustomers extends Command
{
    protected $signature = 'sync:customers
                            {--full : Reset cursor and sync all customers}
                            {--dry-run : Print without syncing}';

    protected $description = 'Sync Odoo customers → Shopify';

    public function handle(): int
    {
        $this->info('Starting customer sync...' . ($this->option('dry-run') ? ' [DRY RUN]' : ''));

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: would sync Odoo customers modified since last cursor.');
            return self::SUCCESS;
        }

        if ($this->option('full')) {
            \App\Models\SyncQueueState::forType('customers')->update([
                'last_odoo_write_date' => null,
                'is_running'           => false,
            ]);
        }

        FetchOdooCustomersJob::dispatchSync();

        $this->info('Customer sync completed.');

        return self::SUCCESS;
    }
}
