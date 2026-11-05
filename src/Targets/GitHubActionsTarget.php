<?php

namespace SegmentHoldings\SsgPublish\Targets;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SegmentHoldings\SsgPublish\State\StateStore;
use SegmentHoldings\SsgPublish\Support\Sanitizer;

class GitHubActionsTarget implements DeployTarget
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
        return (string) ($this->config['label'] ?? $this->handle);
    }

    public function driver(): string
    {
        return 'github_actions';
    }

    public function driverLabel(): string
    {
        return 'GitHub Actions';
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function configured(): bool
    {
        return $this->missingFields() === [];
    }

    public function missingFields(): array
    {
        $missing = [];

        foreach (['owner' => 'owner', 'repo' => 'repository', 'workflow' => 'workflow file', 'ref' => 'git ref', 'token' => 'token'] as $key => $label) {
            if (! is_string($this->config[$key] ?? null) || trim($this->config[$key]) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function secrets(): array
    {
        return array_filter([
            $this->config['token'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');
    }

    public function completesImmediately(): bool
    {
        return false;
    }

    public function dispatch(): TriggerResult
    {
        if (! $this->configured()) {
            return $this->unconfigured();
        }

        $secrets = $this->secrets();

        try {
            $response = $this->client()
                ->withBody(json_encode($this->payload()), 'application/json')
                ->post($this->endpoint('dispatches'));
        } catch (ConnectionException) {
            return new TriggerResult(false, 'failure', 'Could not reach GitHub. Check the server connection and try again.');
        }

        Cache::forget($this->cacheKey());

        if ($this->isRateLimit($response)) {
            return $this->rateLimited($response, $secrets);
        }

        if ($response->status() === 204) {
            return new TriggerResult(
                ok: true,
                state: 'queued',
                message: 'Workflow dispatched. GitHub will queue the run.',
                httpStatus: 204,
            );
        }

        return new TriggerResult(
            ok: false,
            state: 'failure',
            message: $this->failureMessage($response, $secrets),
            httpStatus: $response->status(),
        );
    }

    public function status(bool $live): array
    {
        $trigger = $this->store->lastTrigger($this->handle);

        if (! $live) {
            return $this->present($trigger, null, false);
        }

        if (! $this->configured()) {
            return [
                'state' => 'unconfigured',
                'label' => 'Not configured',
                'message' => $this->unconfigured()->message,
            ];
        }

        $run = Cache::remember($this->cacheKey(), 15, function () {
            return $this->fetchRun();
        });

        if (is_array($run) && ($run['state'] ?? null) === 'success') {
            $finished = isset($run['finished_at']) ? Carbon::parse($run['finished_at']) : now();
            $this->store->markSuccess($finished, $this->handle);
        }

        if (is_array($run) && in_array($run['state'] ?? null, ['success', 'failure'], true)) {
            $this->store->clearAwaiting($this->handle);
        }

        return $this->present($this->store->lastTrigger($this->handle), $run, true);
    }

    private function fetchRun(): array
    {
        $secrets = $this->secrets();

        try {
            $response = $this->client()->get($this->endpoint('runs'), [
                'per_page' => 5,
            ]);
        } catch (ConnectionException) {
            return [
                'state' => 'error',
                'label' => 'Unreachable',
                'message' => 'Could not reach GitHub to check the latest run.',
            ];
        }

        if ($this->isRateLimit($response)) {
            $result = $this->rateLimited($response, $secrets);

            return [
                'state' => 'rate_limited',
                'label' => 'Rate limited',
                'message' => $result->message,
                'retry_at' => $result->retryAt,
            ];
        }

        if (! $response->successful()) {
            return [
                'state' => 'error',
                'label' => 'Unavailable',
                'message' => $this->failureMessage($response, $secrets),
            ];
        }

        $runs = $response->json('workflow_runs');

        if (! is_array($runs) || $runs === []) {
            return $this->waitingOrEmpty();
        }

        $awaiting = $this->store->awaitingSince($this->handle);
        $run = $runs[0];

        if ($awaiting && is_array($run)) {
            $created = isset($run['created_at']) ? Carbon::parse($run['created_at']) : null;
            if ($created && $created->lt($awaiting->copy()->subSeconds(15))) {
                return [
                    'state' => 'queued',
                    'label' => 'Queued',
                    'message' => 'Workflow dispatched. Waiting for GitHub to list the new run.',
                ];
            }
        }

        return $this->mapRun(is_array($run) ? $run : []);
    }

    private function mapRun(array $run): array
    {
        $status = (string) ($run['status'] ?? '');
        $conclusion = (string) ($run['conclusion'] ?? '');

        [$state, $label] = match (true) {
            in_array($status, ['queued', 'waiting', 'pending', 'requested'], true) => ['queued', 'Queued'],
            $status === 'in_progress' => ['in_progress', 'In progress'],
            $status === 'completed' && $conclusion === 'success' => ['success', 'Success'],
            $status === 'completed' => ['failure', 'Failed'],
            default => ['unknown', 'Unknown'],
        };

        $mapped = [
            'state' => $state,
            'label' => $label,
            'message' => $state === 'failure'
                ? 'The latest workflow run did not succeed.'
                : null,
            'url' => Sanitizer::httpsUrl($run['html_url'] ?? null),
            'run_number' => $run['run_number'] ?? null,
            'finished_at' => $run['updated_at'] ?? null,
        ];

        $this->store->rememberRun($this->handle, $mapped);

        return $mapped;
    }

    private function waitingOrEmpty(): array
    {
        if ($this->store->awaitingSince($this->handle)) {
            return [
                'state' => 'queued',
                'label' => 'Queued',
                'message' => 'Workflow dispatched. Waiting for GitHub to list the run.',
            ];
        }

        return [
            'state' => 'unknown',
            'label' => 'No runs',
            'message' => 'GitHub has no runs for this workflow yet.',
        ];
    }

    private function present(?array $trigger, ?array $run, bool $live): array
    {
        if (! $live) {
            return [
                'state' => 'hidden',
                'label' => 'Pro',
                'message' => 'Build status is part of SSG Publish Pro.',
            ];
        }

        if ($run) {
            return $run;
        }

        if ($trigger) {
            return [
                'state' => $trigger['state'] ?? 'unknown',
                'label' => ucfirst(str_replace('_', ' ', (string) ($trigger['state'] ?? 'unknown'))),
                'message' => $trigger['message'] ?? null,
                'url' => Sanitizer::httpsUrl($trigger['url'] ?? null),
            ];
        }

        return [
            'state' => 'unknown',
            'label' => 'Not published',
            'message' => 'This target has not been published yet.',
        ];
    }

    private function payload(): array
    {
        $body = ['ref' => trim((string) $this->config['ref'])];
        $inputs = $this->config['inputs'] ?? [];

        if (is_array($inputs) && $inputs !== []) {
            $body['inputs'] = collect($inputs)
                ->map(fn ($value) => is_scalar($value) ? (string) $value : '')
                ->all();
        }

        return $body;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withOptions(['allow_redirects' => false])
            ->withToken((string) $this->config['token'])
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'User-Agent' => 'ssg-publish',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(15);
    }

    private function endpoint(string $action): string
    {
        $api = rtrim((string) ($this->config['api_url'] ?? 'https://api.github.com'), '/');
        $owner = rawurlencode(trim((string) $this->config['owner']));
        $repo = rawurlencode(trim((string) $this->config['repo']));
        $workflow = rawurlencode(trim((string) $this->config['workflow']));

        return "{$api}/repos/{$owner}/{$repo}/actions/workflows/{$workflow}/{$action}";
    }

    private function cacheKey(): string
    {
        return 'ssg-publish.github.'.$this->handle;
    }

    private function unconfigured(): TriggerResult
    {
        $missing = implode(', ', $this->missingFields());

        return new TriggerResult(
            false,
            'unconfigured',
            "The {$this->label()} target is missing: {$missing}.",
        );
    }

    private function isRateLimit(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        $message = strtolower((string) $response->json('message'));

        return $response->status() === 403 && str_contains($message, 'rate limit');
    }

    private function rateLimited(Response $response, array $secrets): TriggerResult
    {
        $retryAt = null;

        $retryAfter = $response->header('Retry-After');
        if (is_numeric($retryAfter)) {
            $retryAt = now()->addSeconds((int) $retryAfter);
        }

        $reset = $response->header('X-RateLimit-Reset');
        if (! $retryAt && is_numeric($reset)) {
            $retryAt = Carbon::createFromTimestamp((int) $reset);
        }

        $when = $retryAt ? $retryAt->utc()->format('j M Y H:i').' UTC' : null;
        $message = $when
            ? "GitHub rate limit reached. Try again after {$when}."
            : 'GitHub rate limit reached. Wait a minute and try again.';

        return new TriggerResult(
            ok: false,
            state: 'rate_limited',
            message: Sanitizer::text($message, $secrets),
            httpStatus: $response->status(),
            retryAt: $retryAt?->toIso8601String(),
        );
    }

    private function failureMessage(Response $response, array $secrets): string
    {
        $status = $response->status();
        $detail = Sanitizer::text((string) $response->json('message'), $secrets);

        $message = match ($status) {
            401 => 'GitHub rejected the token.',
            403 => 'GitHub refused this token. It needs permission to dispatch workflows on the repository.',
            404 => 'GitHub could not find that workflow. Check the owner, repository, workflow file, and that workflow_dispatch is enabled.',
            422 => 'GitHub could not dispatch that workflow. Check the branch and the workflow inputs.',
            default => 'GitHub returned an error while publishing.',
        };

        return $detail !== '' ? "{$message} {$detail}" : $message;
    }
}
