<?php

namespace SegmentHoldings\SsgPublish;

class AddonConfig
{
    public const KEY = 'ssg-publish';

    public static function all(): array
    {
        $config = config(self::KEY);

        if (is_array($config) && array_key_exists('targets', $config)) {
            return $config;
        }

        $path = dirname(__DIR__).'/config/ssg-publish.php';
        $defaults = is_file($path) ? require $path : [];

        return array_replace_recursive(is_array($defaults) ? $defaults : [], is_array($config) ? $config : []);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return data_get(static::all(), $key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) static::get($key, $default);
    }
}
