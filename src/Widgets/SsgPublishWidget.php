<?php

namespace SegmentHoldings\SsgPublish\Widgets;

use SegmentHoldings\SsgPublish\Http\PublishPage;
use Statamic\Facades\User;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

class SsgPublishWidget extends Widget
{
    public function component()
    {
        $user = User::current();

        if (! $user || $user->cant('access ssg_publish utility')) {
            return VueComponent::render('ssg-publish-widget', [
                'authorized' => false,
            ]);
        }

        $page = app(PublishPage::class)->toArray();
        $target = collect($page['targets'])->first(fn ($target) => ! ($target['locked'] ?? false));

        return VueComponent::render('ssg-publish-widget', [
            'authorized' => true,
            'edition' => $page['edition'],
            'pro' => $page['pro'],
            'utilityUrl' => $page['utilityUrl'],
            'publishUrl' => $page['publishUrl'],
            'target' => $target,
            'changeCount' => $page['pro'] ? count($page['changes']['items']) : null,
        ]);
    }
}
