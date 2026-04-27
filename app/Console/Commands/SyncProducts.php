<?php

namespace App\Console\Commands;

use App\Jobs\Odoo\FetchOdooProductsJob;
use App\Services\Odoo\OdooProductService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Console\Command;

class SyncProducts extends Command
{
    protected $signature = 'sync:products
                            {--full : Ignore cursor, sync all active products}
                            {--limit=0 : Max number of products to process (0 = unlimited)}
                            {--dry-run : Print products without dispatching jobs}';

    protected $description = 'Sync Odoo products → Shopify';

    public function handle(OdooProductService $odooProducts, ProductSyncService $syncService): int
    {
        $full   = $this->option('full');
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('Starting product sync' . ($full ? ' (FULL)' : '') . ($dryRun ? ' [DRY RUN]' : '') . '...');

        if ($dryRun) {
            $products = $full
                ? $odooProducts->getAllActive(0, $limit ?: 100)
                : $odooProducts->getModifiedSince('2000-01-01 00:00:00');

            $this->table(['ID', 'Name', 'Write Date'], array_map(fn($p) => [
                $p['id'], $p['name'], $p['write_date'],
            ], $products));

            $this->info(count($products) . ' product(s) would be synced.');
            return self::SUCCESS;
        }

        if ($full || $limit) {
            $offset = 0;
            $total  = 0;
            $batch  = $limit ?: 100;

            do {
                $products = $odooProducts->getAllActive($offset, $batch);

                foreach ($products as $product) {
                    try {
                        $shopifyId = $syncService->syncProduct($product);
                        $this->line("  ✔ #{$product['id']} {$product['name']} → Shopify #{$shopifyId}");
                    } catch (\Throwable $e) {
                        $this->error("  ✘ #{$product['id']} {$product['name']}: " . $e->getMessage());
                    }
                    $total++;
                    if ($limit && $total >= $limit) {
                        break 2;
                    }
                }

                $offset += count($products);
            } while (count($products) === $batch && (!$limit || $total < $limit));

            $this->info("Done. Synced {$total} product(s).");
        } else {
            FetchOdooProductsJob::dispatchSync(false);
            $this->info('Sync job dispatched to queue.');
        }

        return self::SUCCESS;
    }
}
