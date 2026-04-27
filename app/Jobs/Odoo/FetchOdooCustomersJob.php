<?php

namespace App\Jobs\Odoo;

use App\Jobs\Shopify\PushCustomerToShopifyJob;
use App\Models\SyncQueueState;
use App\Services\Odoo\OdooCustomerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchOdooCustomersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(OdooCustomerService $odooCustomers): void
    {
        $state = SyncQueueState::forType('customers');

        if ($state->is_running) {
            Log::warning('FetchOdooCustomersJob: previous run still active, skipping.');
            return;
        }

        $state->markRunning();

        try {
            $writeDate = $state->last_odoo_write_date ?? '2000-01-01 00:00:00';

            $customers = $odooCustomers->getModifiedSince($writeDate);

            $latestWriteDate = $writeDate;

            foreach ($customers as $customer) {
                PushCustomerToShopifyJob::dispatch($customer);

                if ($customer['write_date'] > $latestWriteDate) {
                    $latestWriteDate = $customer['write_date'];
                }
            }

            $state->markComplete($latestWriteDate);

            Log::info("FetchOdooCustomersJob: dispatched " . count($customers) . " customer jobs");
        } catch (\Throwable $e) {
            $state->update(['is_running' => false, 'notes' => $e->getMessage()]);
            throw $e;
        }
    }
}
