<?php

namespace SegmentHoldings\SsgPublish\Publishing;

use Illuminate\Support\Facades\Cache;
use SegmentHoldings\SsgPublish\Exceptions\PublishException;
use SegmentHoldings\SsgPublish\State\StateStore;
use SegmentHoldings\SsgPublish\Targets\DeployTarget;
use SegmentHoldings\SsgPublish\Targets\TargetRepository;
use SegmentHoldings\SsgPublish\Targets\TriggerResult;

class Publisher
{
    public function __construct(
        private TargetRepository $targets,
        private StateStore $store,
    ) {
    }

    public function publish(?string $handle, string $trigger, ?string $actor = null): TriggerResult
    {
        $target = $handle
            ? $this->targets->availableFor($handle)
            : $this->targets->available()->first();

        if (! $target) {
            throw new PublishException('No deploy targets are configured. Publish config/ssg-publish.php and add a target.');
        }

        return $this->dispatch($target, $trigger, $actor);
    }

    /** @return list<TriggerResult> */
    public function publishMany(iterable $targets, string $trigger, ?string $actor = null): array
    {
        $results = [];

        foreach ($targets as $target) {
            $results[] = $this->dispatch($target, $trigger, $actor);
        }

        return $results;
    }

    private function dispatch(DeployTarget $target, string $trigger, ?string $actor): TriggerResult
    {
        $lock = Cache::lock('ssg-publish-dispatch-'.$target->handle(), 60);

        if (! $lock->get()) {
            throw new PublishException('Another publish is already running for '.$target->label().'. Try again in a moment.');
        }

        try {
            $result = $target->dispatch();
            $payload = $result->toArray();
            $payload['completes'] = $result->completes;
            $this->store->recordTrigger($target->handle(), $payload, $trigger, $actor);

            return $result;
        } finally {
            $lock->release();
        }
    }
}
