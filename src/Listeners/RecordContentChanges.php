<?php

namespace SegmentHoldings\SsgPublish\Listeners;

use SegmentHoldings\SsgPublish\AddonConfig;
use SegmentHoldings\SsgPublish\Changes\ChangeRecorder;
use SegmentHoldings\SsgPublish\Edition;
use SegmentHoldings\SsgPublish\Jobs\PublishAfterDebounce;
use SegmentHoldings\SsgPublish\State\StateStore;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryScheduleReached;
use Statamic\Events\GlobalSetSaved;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;

class RecordContentChanges
{
    public function __construct(
        private ChangeRecorder $changes,
        private StateStore $store,
    ) {
    }

    public function handleEntrySaved(EntrySaved $event): void
    {
        $entry = $event->entry;
        $this->changes->record($entry, 'entry', 'saved', $entry->collectionHandle());
        $this->debounce();
    }

    public function handleEntryDeleted(EntryDeleted $event): void
    {
        $entry = $event->entry;
        $this->changes->record($entry, 'entry', 'deleted', $entry->collectionHandle());
        $this->debounce();
    }

    public function handleTermSaved(TermSaved $event): void
    {
        $term = $event->term;
        $this->changes->record($term, 'term', 'saved', method_exists($term, 'taxonomyHandle') ? $term->taxonomyHandle() : null);
        $this->debounce();
    }

    public function handleTermDeleted(TermDeleted $event): void
    {
        $term = $event->term;
        $this->changes->record($term, 'term', 'deleted', method_exists($term, 'taxonomyHandle') ? $term->taxonomyHandle() : null);
        $this->debounce();
    }

    public function handleGlobalVariablesSaved(GlobalVariablesSaved $event): void
    {
        $variables = $event->variables;
        $set = method_exists($variables, 'globalSet') ? $variables->globalSet() : null;
        $container = is_object($set) && method_exists($set, 'handle') ? $set->handle() : $variables->handle();
        $this->changes->record($variables, 'global', 'saved', $container);
        $this->debounce();
    }

    public function handleGlobalSetSaved(GlobalSetSaved $event): void
    {
        $globals = $event->globals;
        $this->changes->record($globals, 'global_set', 'saved', method_exists($globals, 'handle') ? $globals->handle() : null);
    }

    public function handleEntryScheduleReached(EntryScheduleReached $event): void
    {
        $entry = $event->entry;
        $this->changes->record($entry, 'entry', 'scheduled', $entry->collectionHandle());

        if (! Edition::isPro() || ! AddonConfig::bool('auto_publish_scheduled')) {
            return;
        }

        $date = method_exists($entry, 'date') ? $entry->date() : null;
        $triggered = $this->store->lastTriggeredAt();

        if ($triggered && $date && $triggered->greaterThanOrEqualTo($date)) {
            return;
        }

        $this->store->requestSoon(now(), 'scheduled');
    }

    private function debounce(): void
    {
        if (! Edition::isPro() || ! AddonConfig::bool('auto_publish_on_save')) {
            return;
        }

        $seconds = max(0, (int) AddonConfig::get('debounce_seconds', 60));
        $at = now()->addSeconds($seconds);
        $token = $this->store->debounce($at, 'save');

        if (config('queue.default') !== 'sync') {
            PublishAfterDebounce::dispatch($token)->delay($at);
        }
    }
}
