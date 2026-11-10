<?php

namespace SegmentHoldings\SsgPublish\Targets;

readonly class TriggerResult
{
    public function __construct(
        public bool $ok,
        public string $state,
        public string $message,
        public ?int $httpStatus = null,
        public ?string $responseExcerpt = null,
        public ?string $url = null,
        public ?string $retryAt = null,
        public bool $completes = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'state' => $this->state,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
            'response_excerpt' => $this->responseExcerpt,
            'url' => $this->url,
            'retry_at' => $this->retryAt,
            'completes' => $this->completes,
        ];
    }
}
