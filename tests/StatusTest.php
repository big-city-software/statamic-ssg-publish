<?php

namespace SegmentHoldings\SsgPublish\Tests;

use Illuminate\Support\Facades\Http;
use SegmentHoldings\SsgPublish\Http\PublishPage;
use SegmentHoldings\SsgPublish\State\StateStore;

class StatusTest extends TestCase
{
    public function test_github_run_states_are_mapped_and_linked(): void
    {
        Http::fake([
            'https://api.github.com/*/runs*' => Http::response([
                'workflow_runs' => [[
                    'id' => 99,
                    'status' => 'in_progress',
                    'conclusion' => null,
                    'html_url' => 'https://github.com/segment-holdings/site/actions/runs/99',
                    'run_number' => 12,
                    'created_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]),
        ]);

        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
        ]);

        $status = app(PublishPage::class)->toArray()['targets'][0]['status'];

        $this->assertSame('in_progress', $status['state']);
        $this->assertSame('In progress', $status['label']);
        $this->assertSame('https://github.com/segment-holdings/site/actions/runs/99', $status['url']);
        $this->assertSame(12, $status['run_number']);
        $this->assertStringNotContainsString('ghp_test_token_should_not_leak', json_encode($status));
    }

    public function test_a_successful_run_clears_changes_that_shipped_with_it(): void
    {
        $finished = now()->subMinute();

        Http::fake([
            'https://api.github.com/*/runs*' => Http::response([
                'workflow_runs' => [[
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'html_url' => 'https://github.com/segment-holdings/site/actions/runs/5',
                    'run_number' => 5,
                    'created_at' => $finished->copy()->subMinutes(5)->toIso8601String(),
                    'updated_at' => $finished->toIso8601String(),
                ]],
            ]),
        ]);

        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
        ]);

        $store = app(StateStore::class);
        $store->putChange([
            'key' => 'entry:old',
            'type' => 'entry',
            'id' => 'old',
            'title' => 'Already built',
            'container' => 'blog',
            'locale' => 'default',
            'action' => 'saved',
            'url' => null,
            'changed_at' => $finished->copy()->subHour()->toIso8601String(),
        ]);
        $store->putChange([
            'key' => 'entry:new',
            'type' => 'entry',
            'id' => 'new',
            'title' => 'Still waiting',
            'container' => 'blog',
            'locale' => 'default',
            'action' => 'saved',
            'url' => null,
            'changed_at' => now()->toIso8601String(),
        ]);

        $page = app(PublishPage::class)->toArray();

        $this->assertSame('success', $page['targets'][0]['status']['state']);
        $this->assertCount(1, $page['changes']['items']);
        $this->assertSame('Still waiting', $page['changes']['items'][0]['title']);
    }

    public function test_a_failed_run_is_reported(): void
    {
        Http::fake([
            'https://api.github.com/*/runs*' => Http::response([
                'workflow_runs' => [[
                    'status' => 'completed',
                    'conclusion' => 'failure',
                    'html_url' => 'https://github.com/segment-holdings/site/actions/runs/8',
                    'run_number' => 8,
                    'created_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]),
        ]);

        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
        ]);

        $status = app(PublishPage::class)->toArray()['targets'][0]['status'];

        $this->assertSame('failure', $status['state']);
        $this->assertSame('Failed', $status['label']);
    }

    public function test_status_polling_reports_a_rate_limit(): void
    {
        Http::fake([
            'https://api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 429, [
                'Retry-After' => '30',
            ]),
        ]);

        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
        ]);

        $status = app(PublishPage::class)->toArray()['targets'][0]['status'];

        $this->assertSame('rate_limited', $status['state']);
        $this->assertStringContainsString('GitHub rate limit reached', $status['message']);
    }

    public function test_an_unconfigured_github_target_does_not_call_the_api(): void
    {
        Http::fake();
        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(['token' => '']),
        ]);

        $status = app(PublishPage::class)->toArray()['targets'][0]['status'];

        $this->assertSame('unconfigured', $status['state']);
        $this->assertStringContainsString('token', $status['message']);
        $this->assertStringNotContainsString('ghp_', json_encode($status));
        Http::assertNothingSent();
    }

    public function test_the_free_edition_omits_live_status(): void
    {
        Http::fake();
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
            'preview' => $this->hookTarget(),
        ]);

        $page = app(PublishPage::class)->toArray();

        $this->assertFalse($page['targets'][0]['locked']);
        $this->assertTrue($page['targets'][1]['locked']);
        $this->assertSame('hidden', $page['targets'][0]['status']['state']);
        $this->assertFalse($page['changes']['enabled']);
        Http::assertNothingSent();
    }
}
