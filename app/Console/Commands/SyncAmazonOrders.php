<?php

namespace App\Console\Commands;

use App\Jobs\Amazon\FetchAmazonOrdersJob;
use Illuminate\Console\Command;

class SyncAmazonOrders extends Command
{
    protected $signature = 'sync:amazon-orders
                            {--full : Reset cursor and re-fetch last 30 days}
                            {--dry-run : Show what would be synced}';

    protected $description = 'Fetch Amazon orders → create in Odoo';

    public function handle(): int
    {
        $this->info('Amazon order sync...' . ($this->option('dry-run') ? ' [DRY RUN]' : ''));

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: would fetch Amazon orders updated since last cursor and create in Odoo.');
            return self::SUCCESS;
        }

        if ($this->option('full')) {
            \App\Models\SyncQueueState::forType('amazon_orders')->update([
                'last_odoo_write_date' => null,
                'is_running'           => false,
            ]);
            $this->info('Cursor reset — will fetch last 30 days.');
        }

        FetchAmazonOrdersJob::dispatchSync();

        $this->info('Amazon order sync completed.');

        return self::SUCCESS;
    }
}
