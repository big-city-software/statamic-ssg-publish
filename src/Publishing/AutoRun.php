<?php

namespace SegmentHoldings\SsgPublish\Publishing;

readonly class AutoRun
{
    /** @param list<string> $lines */
    public function __construct(public int $exit, public array $lines)
    {
    }
}
