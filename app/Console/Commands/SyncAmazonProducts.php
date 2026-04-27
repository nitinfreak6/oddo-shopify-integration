<?php

namespace App\Console\Commands;

use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Services\Odoo\OdooProductService;
use App\Services\Sync\AmazonProductSyncService;
use Illuminate\Console\Command;

class SyncAmazonProducts extends Command
{
    protected $signature = 'sync:amazon-products
                            {--full : Sync all active products (ignore cursor)}
                            {--limit=0 : Max products to process}
                            {--dry-run : Show what would sync without pushing}';

    protected $description = 'Sync Odoo products → Amazon listings';

    public function handle(OdooProductService $odooProducts, AmazonProductSyncService $syncService): int
    {
        $full   = $this->option('full');
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('Amazon product sync' . ($full ? ' (FULL)' : '') . ($dryRun ? ' [DRY RUN]' : ''));

        $offset = 0;
        $total  = 0;
        $batch  = $limit ?: 100;

        do {
            $products = $odooProducts->getAllActive($offset, $batch);

            foreach ($products as $product) {
                if ($dryRun) {
                    $this->line("  Would sync: #{$product['id']} {$product['name']} (SKUs: "
                        . implode(',', array_column($product['product_variant_ids'] ?? [], null)) . ')');
                } else {
                    try {
                        $result = $syncService->syncProduct($product);
                        $synced = implode(',', $result['synced']);
                        $failed = implode(',', $result['failed']);
                        $this->line("  ✔ #{$product['id']} {$product['name']} → SKUs: {$synced}"
                            . ($failed ? " | FAILED: {$failed}" : ''));
                    } catch (\Throwable $e) {
                        $this->error("  ✘ #{$product['id']}: " . $e->getMessage());
                    }
                }

                $total++;
                if ($limit && $total >= $limit) {
                    break 2;
                }
            }

            $offset += count($products);
        } while (count($products) === $batch && (!$limit || $total < $limit));

        $this->info("Done. Processed {$total} product(s).");

        return self::SUCCESS;
    }
}
