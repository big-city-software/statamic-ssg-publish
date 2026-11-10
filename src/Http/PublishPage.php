<?php

namespace SegmentHoldings\SsgPublish\Http;

use SegmentHoldings\SsgPublish\AddonConfig;
use SegmentHoldings\SsgPublish\Edition;
use SegmentHoldings\SsgPublish\State\StateStore;
use SegmentHoldings\SsgPublish\Support\Sanitizer;
use SegmentHoldings\SsgPublish\Targets\TargetRepository;

class PublishPage
{
    public function __construct(
        private TargetRepository $targets,
        private StateStore $store,
    ) {
    }

    public function toArray(): array
    {
        $pro = Edition::isPro();
        $available = $this->targets->available();

        $targets = $this->targets->all()->values()->map(function ($target) use ($available, $pro) {
            $locked = ! $available->has($target->handle());
            $live = $pro && ! $locked;

            $status = $live
                ? $target->status(true)
                : [
                    'state' => $locked ? 'locked' : 'hidden',
                    'label' => $locked ? 'Pro' : 'Manual',
                    'message' => $locked
                        ? 'Additional targets require SSG Publish Pro.'
                        : 'Build status is part of SSG Publish Pro.',
                ];

            return [
                'handle' => $target->handle(),
                'label' => $target->label(),
                'driver' => $target->driver(),
                'driver_label' => $target->driverLabel(),
                'configured' => $target->configured(),
                'missing' => $target->missingFields(),
                'locked' => $locked,
                'status' => $this->publicStatus($status),
            ];
        })->all();

        return [
            'edition' => Edition::current(),
            'pro' => $pro,
            'publishUrl' => $this->route('utilities.ssg-publish.publish'),
            'statusUrl' => $this->route('utilities.ssg-publish.status'),
            'utilityUrl' => $this->route('utilities.ssg-publish'),
            'targets' => $targets,
            'changes' => $pro ? [
                'enabled' => true,
                'last_successful_publish_at' => $this->store->lastSuccessfulPublishAt()?->toIso8601String(),
                'items' => array_values($this->store->changes()),
            ] : [
                'enabled' => false,
                'last_successful_publish_at' => null,
                'items' => [],
            ],
            'auto' => [
                'on_save' => $pro && AddonConfig::bool('auto_publish_on_save'),
                'scheduled' => $pro && AddonConfig::bool('auto_publish_scheduled'),
                'debounce_seconds' => (int) AddonConfig::get('debounce_seconds', 60),
            ],
        ];
    }

    private function publicStatus(array $status): array
    {
        return [
            'state' => $status['state'] ?? 'unknown',
            'label' => $status['label'] ?? 'Unknown',
            'message' => isset($status['message']) ? Sanitizer::text((string) $status['message']) : null,
            'url' => Sanitizer::httpsUrl($status['url'] ?? null),
            'run_number' => $status['run_number'] ?? null,
            'triggered_at' => $status['triggered_at'] ?? null,
            'http_status' => $status['http_status'] ?? null,
            'response_excerpt' => isset($status['response_excerpt']) && is_string($status['response_excerpt'])
                ? Sanitizer::text($status['response_excerpt'])
                : null,
        ];
    }

    private function route(string $name): ?string
    {
        try {
            return cp_route($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
