<?php

namespace SegmentHoldings\SsgPublish\Changes;

use SegmentHoldings\SsgPublish\State\StateStore;

class ChangeRecorder
{
    public function __construct(private StateStore $store)
    {
    }

    public function record(object $model, string $type, string $action, ?string $container = null): void
    {
        $id = method_exists($model, 'id') ? (string) $model->id() : null;

        if ($id === null || $id === '') {
            return;
        }

        $locale = null;
        if (method_exists($model, 'locale')) {
            $locale = $model->locale();
        } elseif (method_exists($model, 'site')) {
            $site = $model->site();
            $locale = is_object($site) && method_exists($site, 'handle') ? $site->handle() : (is_string($site) ? $site : null);
        }

        $title = $this->title($model);

        $this->store->putChange([
            'key' => $type.':'.$id.($locale ? ':'.$locale : ''),
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'container' => $container,
            'locale' => $locale,
            'action' => $action,
            'url' => $this->url($model),
            'changed_at' => now()->toIso8601String(),
        ]);
    }

    private function title(object $model): string
    {
        $title = null;

        if (method_exists($model, 'value')) {
            $title = $model->value('title');
        }

        if (! is_string($title) || $title === '') {
            $title = method_exists($model, 'title') ? $model->title() : null;
        }

        if (! is_string($title) || $title === '') {
            $title = method_exists($model, 'slug') ? $model->slug() : 'Untitled';
        }

        return mb_substr($title, 0, 180);
    }

    private function url(object $model): ?string
    {
        if (! method_exists($model, 'editUrl')) {
            return null;
        }

        try {
            $url = $model->editUrl();
        } catch (\Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }
}
