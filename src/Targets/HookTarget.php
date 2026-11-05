<?php

namespace SegmentHoldings\SsgPublish\Targets;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SegmentHoldings\SsgPublish\State\StateStore;
use SegmentHoldings\SsgPublish\Support\Sanitizer;

class HookTarget implements DeployTarget
{
    public function __construct(
        private string $handle,
        private array $config,
        private StateStore $store,
    ) {
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? $this->driverLabel());
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'hook');
    }

    public function driverLabel(): string
    {
        return match ($this->driver()) {
            'cloudflare_pages' => 'Cloudflare Pages',
            'netlify' => 'Netlify',
            'vercel' => 'Vercel',
            default => 'Deploy hook',
        };
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function configured(): bool
    {
        return $this->missingFields() === [] && $this->validHook();
    }

    public function missingFields(): array
    {
        $url = $this->config['hook_url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return ['hook URL'];
        }

        if (! $this->validHook()) {
            return ['an HTTPS hook URL'];
        }

        return [];
    }

    public function secrets(): array
    {
        $url = $this->config['hook_url'] ?? null;

        return is_string($url) && $url !== '' ? [$url] : [];
    }

    public function completesImmediately(): bool
    {
        return true;
    }

    public function dispatch(): TriggerResult
    {
        if (! $this->configured()) {
            $missing = implode(', ', $this->missingFields());

            return new TriggerResult(false, 'unconfigured', "The {$this->label()} target is missing: {$missing}.");
        }

        $url = trim((string) $this->config['hook_url']);
        $secrets = $this->secrets();

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'User-Agent' => 'ssg-publish',
                    'Accept' => 'application/json',
                ])
                ->timeout(15)
                ->withBody('', 'text/plain')
                ->post($url);
        } catch (ConnectionException) {
            return new TriggerResult(false, 'failure', "Could not reach the {$this->driverLabel()} deploy hook.");
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            return new TriggerResult(
                false,
                'failure',
                "The {$this->driverLabel()} hook returned a redirect. Check the hook URL.",
                $response->status(),
            );
        }

        $excerpt = Sanitizer::text($response->body(), $secrets);

        if ($response->successful()) {
            return new TriggerResult(
                ok: true,
                state: 'success',
                message: "{$this->driverLabel()} accepted the deploy hook.",
                httpStatus: $response->status(),
                responseExcerpt: $excerpt !== '' ? $excerpt : null,
                completes: true,
            );
        }

        $message = "The {$this->driverLabel()} hook returned HTTP {$response->status()}.";
        if ($excerpt !== '') {
            $message .= ' '.$excerpt;
        }

        return new TriggerResult(
            ok: false,
            state: 'failure',
            message: Sanitizer::text($message, $secrets),
            httpStatus: $response->status(),
            responseExcerpt: $excerpt !== '' ? $excerpt : null,
        );
    }

    public function status(bool $live): array
    {
        $trigger = $this->store->lastTrigger($this->handle);

        if (! $live) {
            return [
                'state' => 'hidden',
                'label' => 'Pro',
                'message' => 'Build status is part of SSG Publish Pro.',
            ];
        }

        if (! $trigger) {
            return [
                'state' => $this->configured() ? 'unknown' : 'unconfigured',
                'label' => $this->configured() ? 'Not published' : 'Not configured',
                'message' => $this->configured()
                    ? 'This hook has not been triggered yet.'
                    : 'Add an HTTPS hook URL to this target.',
            ];
        }

        $state = (string) ($trigger['state'] ?? 'unknown');

        return [
            'state' => $state,
            'label' => $state === 'success' ? 'Accepted' : ucfirst(str_replace('_', ' ', $state)),
            'message' => $trigger['message'] ?? null,
            'triggered_at' => $trigger['at'] ?? null,
            'http_status' => $trigger['http_status'] ?? null,
            'response_excerpt' => $trigger['response_excerpt'] ?? null,
        ];
    }

    private function validHook(): bool
    {
        $url = trim((string) ($this->config['hook_url'] ?? ''));

        return Sanitizer::httpsUrl($url) !== null;
    }
}
