<?php

namespace SegmentHoldings\SsgPublish\Tests;

use Illuminate\Support\Facades\Http;
use SegmentHoldings\SsgPublish\State\StateStore;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class ScheduledPublishTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('blog')->title('Blog')->dated(true)->save();
    }

    public function test_the_first_run_does_not_rebuild_history(): void
    {
        Http::fake();
        $this->useEdition('pro');
        config()->set('ssg-publish.auto_publish_scheduled', true);
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget(),
        ]);

        Entry::make()
            ->id('old-news')
            ->collection('blog')
            ->slug('old-news')
            ->date(now()->subDay())
            ->data(['title' => 'Old news'])
            ->published(true)
            ->save();

        $this->artisan('ssg-publish:scheduled')
            ->expectsOutputToContain('Nothing is due.')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNotNull(app(StateStore::class)->scheduledCursor());
    }

    public function test_a_due_entry_publishes_on_pro(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response('ok', 200),
        ]);

        $this->useEdition('pro');
        config()->set('ssg-publish.auto_publish_scheduled', true);
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget('cloudflare_pages'),
        ]);

        app(StateStore::class)->setScheduledCursor(now()->subHours(2));

        Entry::make()
            ->id('went-live')
            ->collection('blog')
            ->slug('went-live')
            ->date(now()->subMinutes(30))
            ->data(['title' => 'Went live'])
            ->published(true)
            ->save();

        $this->artisan('ssg-publish:scheduled')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_the_free_edition_does_not_auto_publish(): void
    {
        Http::fake();
        config()->set('ssg-publish.auto_publish_scheduled', true);
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget(),
        ]);
        app(StateStore::class)->setScheduledCursor(now()->subHour());

        Entry::make()
            ->id('due-free')
            ->collection('blog')
            ->slug('due-free')
            ->date(now()->subMinute())
            ->data(['title' => 'Due'])
            ->published(true)
            ->save();

        $this->artisan('ssg-publish:scheduled')
            ->expectsOutputToContain('SSG Publish Pro')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_saves_are_debounced_into_one_publish(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response('ok', 201),
        ]);

        $this->useEdition('pro');
        config()->set('ssg-publish.auto_publish_on_save', true);
        config()->set('ssg-publish.debounce_seconds', 120);
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget('vercel'),
        ]);

        $entry = Entry::make()
            ->id('debounced')
            ->collection('blog')
            ->slug('debounced')
            ->data(['title' => 'Debounced'])
            ->published(true);
        $entry->save();
        $entry->set('title', 'Debounced again')->save();

        $this->artisan('ssg-publish:scheduled')
            ->expectsOutputToContain('Nothing is due.')
            ->assertSuccessful();

        Http::assertNothingSent();

        $this->travel(3)->minutes();

        $this->artisan('ssg-publish:scheduled')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_a_future_entry_is_not_due_yet(): void
    {
        Http::fake();
        $this->useEdition('pro');
        config()->set('ssg-publish.auto_publish_scheduled', true);
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget(),
        ]);
        app(StateStore::class)->setScheduledCursor(now()->subMinute());

        Entry::make()
            ->id('later')
            ->collection('blog')
            ->slug('later')
            ->date(now()->addDay())
            ->data(['title' => 'Later'])
            ->published(true)
            ->save();

        $this->artisan('ssg-publish:scheduled')->assertSuccessful();

        Http::assertNothingSent();
    }
}
