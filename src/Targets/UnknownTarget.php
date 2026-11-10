<?php

namespace SegmentHoldings\SsgPublish\Targets;

class UnknownTarget implements DeployTarget
{
    public function __construct(private string $handle, private array $config)
    {
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? $this->handle);
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'unknown');
    }

    public function driverLabel(): string
    {
        return 'Unknown driver';
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function configured(): bool
    {
        return false;
    }

    public function missingFields(): array
    {
        return ['a supported driver'];
    }

    public function secrets(): array
    {
        return [];
    }

    public function completesImmediately(): bool
    {
        return true;
    }

    public function dispatch(): TriggerResult
    {
        return new TriggerResult(
            false,
            'unconfigured',
            "Target [{$this->handle}] uses an unknown driver [{$this->driver()}].",
        );
    }

    public function status(bool $live): array
    {
        return [
            'state' => 'unconfigured',
            'label' => 'Not configured',
            'message' => "Target [{$this->handle}] uses an unknown driver [{$this->driver()}].",
        ];
    }
}
