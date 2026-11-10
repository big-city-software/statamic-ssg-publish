<?php

namespace SegmentHoldings\SsgPublish;

use Statamic\Facades\Addon;

class Edition
{
    public const PACKAGE = 'big-city-software/statamic-ssg-publish';

    public static function current(): string
    {
        return Addon::get(self::PACKAGE)?->edition() ?: 'free';
    }

    public static function isPro(): bool
    {
        return static::current() === 'pro';
    }
}
