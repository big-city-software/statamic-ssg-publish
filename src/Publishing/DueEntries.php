<?php

namespace SegmentHoldings\SsgPublish\Publishing;

use Illuminate\Support\Collection;
use SegmentHoldings\SsgPublish\State\StateStore;
use Statamic\Facades\Entry;

class DueEntries
{
    public function __construct(private StateStore $store)
    {
    }

    public function sinceCursor(): Collection
    {
        $cursor = $this->store->scheduledCursor();

        if (! $cursor) {
            return collect();
        }

        return Entry::query()->whereStatus('published')->get()->filter(function ($entry) use ($cursor) {
            if (! $entry->published() || ! $entry->hasDate()) {
                return false;
            }

            $date = $entry->date();

            return $date && $date->gt($cursor) && $date->lte(now());
        })->values();
    }
}
