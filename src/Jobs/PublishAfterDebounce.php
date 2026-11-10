<?php

namespace SegmentHoldings\SsgPublish\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SegmentHoldings\SsgPublish\Publishing\AutoPublisher;

class PublishAfterDebounce implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $token)
    {
    }

    public function handle(AutoPublisher $publisher): void
    {
        $publisher->publishPendingToken($this->token);
    }
}
