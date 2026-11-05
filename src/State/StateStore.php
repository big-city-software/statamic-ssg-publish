<?php

namespace SegmentHoldings\SsgPublish\State;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class StateStore
{
    public function path(): string
    {
        return storage_path('statamic/ssg-publish/state.json');
    }

    public function get(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return $this->blank();
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_replace_recursive($this->blank(), $decoded) : $this->blank();
    }

    public function forget(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function putChange(array $change): void
    {
        $this->update(function (array $state) use ($change) {
            $state['changes'][$change['key']] = $change;
            $state['changes'] = collect($state['changes'])
                ->sortBy('changed_at')
                ->slice(-100)
                ->all();

            return $state;
        });
    }

    public function changes(): array
    {
        return array_values($this->get()['changes']);
    }

    public function debounce(CarbonInterface $at, string $reason): string
    {
        $token = (string) Str::uuid();

        $this->update(function (array $state) use ($at, $reason, $token) {
            $state['pending'] = [
                'at' => $at->toIso8601String(),
                'token' => $token,
                'reason' => $reason,
            ];

            return $state;
        });

        return $token;
    }

    public function requestSoon(CarbonInterface $at, string $reason): void
    {
        $this->update(function (array $state) use ($at, $reason) {
            $existing = isset($state['pending']['at']) ? Carbon::parse($state['pending']['at']) : null;

            if ($existing && $existing->lte($at)) {
                return $state;
            }

            $state['pending'] = [
                'at' => $at->toIso8601String(),
                'token' => (string) Str::uuid(),
                'reason' => $reason,
            ];

            return $state;
        });
    }

    public function pending(): ?array
    {
        $pending = $this->get()['pending'] ?? null;

        return is_array($pending) && isset($pending['at']) ? $pending : null;
    }

    public function pendingIsDue(): bool
    {
        $pending = $this->pending();

        return $pending && Carbon::parse($pending['at'])->lte(now());
    }

    public function clearPending(): void
    {
        $this->update(function (array $state) {
            $state['pending'] = null;

            return $state;
        });
    }

    public function recordTrigger(string $handle, array $result, string $trigger, ?string $actor): void
    {
        $this->update(function (array $state) use ($handle, $result, $trigger, $actor) {
            $state['targets'][$handle]['last_trigger'] = [
                'at' => now()->toIso8601String(),
                'ok' => (bool) ($result['ok'] ?? false),
                'state' => $result['state'] ?? 'failure',
                'http_status' => $result['http_status'] ?? null,
                'message' => $result['message'] ?? '',
                'response_excerpt' => $result['response_excerpt'] ?? null,
                'url' => $result['url'] ?? null,
                'trigger' => $trigger,
                'actor' => $actor,
            ];

            if ($result['ok'] ?? false) {
                $state['last_triggered_at'] = now()->toIso8601String();
                $state['pending'] = null;

                if ($result['completes'] ?? false) {
                    $state = $this->applySuccess($state, now(), $handle);
                } else {
                    $state['targets'][$handle]['awaiting_since'] = now()->toIso8601String();
                }
            }

            if (($result['state'] ?? null) === 'rate_limited' && ! empty($result['retry_at'])) {
                $state['backoff_until'] = $result['retry_at'];
            }

            return $state;
        });
    }

    public function markSuccess(CarbonInterface $at, ?string $handle = null): void
    {
        $this->update(fn (array $state) => $this->applySuccess($state, $at, $handle));
    }

    public function clearAwaiting(string $handle): void
    {
        $this->update(function (array $state) use ($handle) {
            $state['targets'][$handle]['awaiting_since'] = null;

            return $state;
        });
    }

    public function awaitingSince(string $handle): ?CarbonInterface
    {
        $value = $this->get()['targets'][$handle]['awaiting_since'] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function lastTrigger(string $handle): ?array
    {
        $trigger = $this->get()['targets'][$handle]['last_trigger'] ?? null;

        return is_array($trigger) ? $trigger : null;
    }

    public function lastTriggeredAt(): ?CarbonInterface
    {
        $value = $this->get()['last_triggered_at'] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function lastSuccessfulPublishAt(): ?CarbonInterface
    {
        $value = $this->get()['last_successful_publish_at'] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function scheduledCursor(): ?CarbonInterface
    {
        $value = $this->get()['scheduled_cursor'] ?? null;

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function setScheduledCursor(CarbonInterface $at): void
    {
        $this->update(function (array $state) use ($at) {
            $state['scheduled_cursor'] = $at->toIso8601String();

            return $state;
        });
    }

    public function inBackoff(): bool
    {
        $value = $this->get()['backoff_until'] ?? null;

        return is_string($value) && Carbon::parse($value)->isFuture();
    }

    public function rememberRun(string $handle, array $run): void
    {
        $this->update(function (array $state) use ($handle, $run) {
            $state['targets'][$handle]['run'] = $run;

            return $state;
        });
    }

    public function rememberedRun(string $handle): ?array
    {
        $run = $this->get()['targets'][$handle]['run'] ?? null;

        return is_array($run) ? $run : null;
    }

    private function applySuccess(array $state, CarbonInterface $at, ?string $handle = null): array
    {
        $current = isset($state['last_successful_publish_at'])
            ? Carbon::parse($state['last_successful_publish_at'])
            : null;

        if ($handle) {
            $state['targets'][$handle]['awaiting_since'] = null;
        }

        if ($current && $current->greaterThan($at)) {
            return $state;
        }

        $state['last_successful_publish_at'] = $at->toIso8601String();
        $state['changes'] = collect($state['changes'])
            ->reject(fn ($change) => isset($change['changed_at']) && Carbon::parse($change['changed_at'])->lte($at))
            ->all();

        return $state;
    }

    private function update(callable $callback): array
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new \RuntimeException('SSG Publish could not open its state file.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('SSG Publish could not lock its state file.');
            }

            $contents = stream_get_contents($handle);
            $state = $this->blank();

            if (is_string($contents) && $contents !== '') {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    $state = array_replace_recursive($state, $decoded);
                }
            }

            $state = $callback($state);
            $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded === false ? '{}' : $encoded);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $state;
    }

    private function blank(): array
    {
        return [
            'changes' => [],
            'last_successful_publish_at' => null,
            'last_triggered_at' => null,
            'pending' => null,
            'backoff_until' => null,
            'scheduled_cursor' => null,
            'targets' => [],
        ];
    }
}
