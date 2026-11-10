<?php

namespace SegmentHoldings\SsgPublish\Publishing;

use SegmentHoldings\SsgPublish\AddonConfig;
use SegmentHoldings\SsgPublish\Edition;
use SegmentHoldings\SsgPublish\Exceptions\PublishException;
use SegmentHoldings\SsgPublish\State\StateStore;
use SegmentHoldings\SsgPublish\Targets\TargetRepository;

class AutoPublisher
{
    public function __construct(
        private Publisher $publisher,
        private TargetRepository $targets,
        private StateStore $store,
        private DueEntries $dueEntries,
    ) {
    }

    public function run(): AutoRun
    {
        if (! Edition::isPro()) {
            return new AutoRun(0, ['Scheduled auto-publish requires SSG Publish Pro.']);
        }

        $this->refreshInFlightTargets();

        $scheduled = AddonConfig::bool('auto_publish_scheduled');
        $onSave = AddonConfig::bool('auto_publish_on_save');

        if (! $scheduled && ! $onSave) {
            return new AutoRun(0, ['Auto-publish is turned off.']);
        }

        if ($this->store->inBackoff()) {
            return new AutoRun(0, ['Auto-publish is waiting for a rate limit to reset.']);
        }

        if ($this->buildInProgress()) {
            return new AutoRun(0, ['Auto-publish is waiting for the current build to finish.']);
        }

        $reasons = [];

        if ($onSave && $this->store->pendingIsDue()) {
            $reasons[] = $this->store->pending()['reason'] ?? 'save';
        }

        if ($scheduled) {
            if ($this->store->scheduledCursor() === null) {
                $this->store->setScheduledCursor(now());
                $reasons[] = 'baseline';
            } elseif ($this->dueEntries->sinceCursor()->isNotEmpty()) {
                $reasons[] = 'scheduled';
            }
        }

        $reasons = array_values(array_filter($reasons, fn ($reason) => $reason !== 'baseline'));

        if ($reasons === []) {
            if ($scheduled && $this->store->scheduledCursor()) {
                $this->store->setScheduledCursor(now());
            }

            return new AutoRun(0, ['Nothing is due.']);
        }

        $targets = $this->targets->automatic();

        if ($targets->isEmpty()) {
            return new AutoRun(1, ['Auto-publish has no targets.']);
        }

        $lines = [];
        $failed = false;

        foreach ($targets as $target) {
            try {
                $result = $this->publisher->publish($target->handle(), $reasons[0] === 'scheduled' ? 'scheduled' : 'save', null);
            } catch (PublishException $e) {
                $failed = true;
                $lines[] = $target->label().': '.$e->getMessage();

                continue;
            }

            $lines[] = $target->label().': '.$result->message;
            $failed = $failed || ! $result->ok;
        }

        if (! $failed && $scheduled) {
            $this->store->setScheduledCursor(now());
        }

        return new AutoRun($failed ? 1 : 0, $lines);
    }

    public function publishPendingToken(string $token): void
    {
        $pending = $this->store->pending();

        if (! $pending || ($pending['token'] ?? null) !== $token) {
            return;
        }

        if (now()->lt(\Illuminate\Support\Carbon::parse($pending['at']))) {
            return;
        }

        $this->run();
    }

    private function refreshInFlightTargets(): void
    {
        if (! Edition::isPro()) {
            return;
        }

        foreach ($this->targets->available() as $target) {
            if ($target->driver() !== 'github_actions') {
                continue;
            }

            if ($this->store->awaitingSince($target->handle())) {
                $target->status(true);
            }
        }
    }

    private function buildInProgress(): bool
    {
        foreach ($this->targets->automatic() as $target) {
            if ($target->driver() !== 'github_actions' || ! $target->configured()) {
                continue;
            }

            $status = $target->status(true);
            if (in_array($status['state'] ?? null, ['queued', 'in_progress'], true)) {
                return true;
            }
        }

        return false;
    }
}
