<?php

namespace SegmentHoldings\SsgPublish\Targets;

use Illuminate\Support\Collection;
use SegmentHoldings\SsgPublish\AddonConfig;
use SegmentHoldings\SsgPublish\Edition;
use SegmentHoldings\SsgPublish\Exceptions\PublishException;
use SegmentHoldings\SsgPublish\State\StateStore;

class TargetRepository
{
    public function __construct(private StateStore $store)
    {
    }

    /** @return Collection<string, DeployTarget> */
    public function all(): Collection
    {
        return collect(AddonConfig::get('targets', []))
            ->map(fn ($config, $handle) => $this->make((string) $handle, is_array($config) ? $config : []))
            ->filter(fn (DeployTarget $target) => $target->enabled());
    }

    /** @return Collection<string, DeployTarget> */
    public function available(): Collection
    {
        $all = $this->all()->values();

        if (Edition::isPro()) {
            return $this->all();
        }

        $first = $all->take(1);

        return $first->keyBy(fn (DeployTarget $target) => $target->handle());
    }

    public function find(string $handle): ?DeployTarget
    {
        return $this->all()->get($handle);
    }

    public function availableFor(string $handle): DeployTarget
    {
        $target = $this->find($handle);

        if (! $target) {
            throw new PublishException("Unknown target [{$handle}].");
        }

        if (! $this->available()->has($handle)) {
            throw new PublishException('That target requires SSG Publish Pro. The free edition publishes the first configured target only.');
        }

        return $target;
    }

    /** @return Collection<string, DeployTarget> */
    public function automatic(): Collection
    {
        $available = $this->available();
        $selected = AddonConfig::get('auto_targets');

        if (! is_array($selected)) {
            return $available;
        }

        return $available->only($selected);
    }

    private function make(string $handle, array $config): DeployTarget
    {
        $driver = $config['driver'] ?? null;

        return match ($driver) {
            'github_actions' => new GitHubActionsTarget($handle, $config, $this->store),
            'cloudflare_pages', 'netlify', 'vercel', 'hook' => new HookTarget($handle, $config, $this->store),
            default => new UnknownTarget($handle, $config),
        };
    }
}
