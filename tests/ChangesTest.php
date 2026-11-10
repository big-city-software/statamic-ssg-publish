<?php

namespace SegmentHoldings\SsgPublish\Tests;

use SegmentHoldings\SsgPublish\Http\PublishPage;
use SegmentHoldings\SsgPublish\State\StateStore;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

class ChangesTest extends TestCase
{
    public function test_saves_are_recorded_once_per_item_and_hidden_on_the_free_edition(): void
    {
        Collection::make('blog')->title('Blog')->dated(true)->save();

        $entry = Entry::make()
            ->id('entry-hello')
            ->collection('blog')
            ->slug('hello')
            ->data(['title' => 'Secret Draft Title XYZ'])
            ->published(true);
        $entry->save();
        $entry->set('title', 'Secret Draft Title XYZ')->save();

        Taxonomy::make('topics')->title('Topics')->save();
        Term::make('news')->taxonomy('topics')->data(['title' => 'News'])->save();

        $set = GlobalSet::make('company')->title('Company');
        $set->save();
        $set->inDefaultSite()->set('phone', '555')->save();

        $stored = app(StateStore::class)->changes();
        $types = collect($stored)->pluck('type')->all();

        $this->assertContains('entry', $types);
        $this->assertContains('term', $types);
        $this->assertContains('global', $types);
        $this->assertCount(1, collect($stored)->where('type', 'entry'));

        $page = app(PublishPage::class)->toArray();
        $this->assertFalse($page['changes']['enabled']);
        $this->assertSame([], $page['changes']['items']);
        $this->assertStringNotContainsString('Secret Draft Title XYZ', json_encode($page));

        $this->useEdition('pro');
        $pro = app(PublishPage::class)->toArray();
        $this->assertTrue($pro['changes']['enabled']);
        $this->assertTrue(collect($pro['changes']['items'])->pluck('title')->contains('Secret Draft Title XYZ'));
    }
}
