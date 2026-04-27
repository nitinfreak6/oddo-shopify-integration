<?php

namespace App\Jobs\Amazon;

use App\Models\AmazonFeedJob;
use App\Services\Amazon\AmazonListingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PollAmazonFeedStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 48;    // Poll up to 48 times (4 hours at 5-min intervals)
    public int $timeout = 30;

    public function __construct(private readonly int $feedJobId)
    {
        $this->onQueue('sync');
    }

    public function handle(AmazonListingService $listingService): void
    {
        $feedJob = AmazonFeedJob::find($this->feedJobId);

        if (!$feedJob) {
            return;
        }

        if ($feedJob->isTerminal()) {
            Log::debug("PollAmazonFeedStatusJob: feed {$feedJob->feed_id} already terminal, skipping.");
            return;
        }

        $isDone = $listingService->pollFeed($feedJob);

        if (!$isDone) {
            // Re-dispatch with delay until terminal or max tries exhausted
            $pollSeconds = config('amazon.feed_poll_seconds', 300);

            self::dispatch($this->feedJobId)
                ->onQueue('sync')
                ->delay(now()->addSeconds($pollSeconds));

            Log::debug("Feed {$feedJob->feed_id} still processing, re-polling in {$pollSeconds}s.");
        } else {
            Log::info("Feed {$feedJob->feed_id} completed with status: {$feedJob->status}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PollAmazonFeedStatusJob failed', [
            'feed_job_id' => $this->feedJobId,
            'error'       => $e->getMessage(),
        ]);
    }
}
