<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyService;
use Illuminate\Console\Command;

class RegisterShopifyWebhooks extends Command
{
    protected $signature = 'shopify:register-webhooks {--force : Delete existing webhooks first}';
    protected $description = 'Register webhook subscriptions in Shopify';

    private array $topics = [
        'orders/create',
        'orders/updated',
        'inventory_levels/update',
    ];

    public function handle(ShopifyService $shopify): int
    {
        $callbackBase = rtrim(config('shopify.callback_url'), '/');

        if (!$callbackBase) {
            $this->error('SHOPIFY_WEBHOOK_CALLBACK_URL is not set in .env');
            return self::FAILURE;
        }

        if ($this->option('force')) {
            $this->info('Deleting existing webhooks...');
            $existing = $shopify->get('webhooks.json')['webhooks'] ?? [];
            foreach ($existing as $webhook) {
                $shopify->delete("webhooks/{$webhook['id']}.json");
                $this->line("  Deleted webhook #{$webhook['id']} ({$webhook['topic']})");
            }
        }

        foreach ($this->topics as $topic) {
            $path     = str_replace('/', '/', $topic);
            $endpoint = "{$callbackBase}/{$path}";

            try {
                $result = $shopify->post('webhooks.json', [
                    'webhook' => [
                        'topic'   => $topic,
                        'address' => $endpoint,
                        'format'  => 'json',
                    ],
                ]);

                $id = $result['webhook']['id'] ?? '?';
                $this->info("  ✔ Registered: {$topic} → {$endpoint} (ID: #{$id})");
            } catch (\Throwable $e) {
                $this->error("  ✘ Failed: {$topic}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
