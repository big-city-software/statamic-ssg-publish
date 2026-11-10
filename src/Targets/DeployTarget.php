<?php

namespace SegmentHoldings\SsgPublish\Targets;

interface DeployTarget
{
    public function handle(): string;

    public function label(): string;

    public function driver(): string;

    public function driverLabel(): string;

    public function enabled(): bool;

    public function configured(): bool;

    /** @return list<string> */
    public function missingFields(): array;

    /** @return list<string> */
    public function secrets(): array;

    public function completesImmediately(): bool;

    public function dispatch(): TriggerResult;

    /** @return array<string, mixed> */
    public function status(bool $live): array;
}
