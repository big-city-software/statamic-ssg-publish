<?php

namespace SegmentHoldings\SsgPublish\Tests;

use Illuminate\Support\Facades\Http;
use SegmentHoldings\SsgPublish\Http\PublishPage;
use SegmentHoldings\SsgPublish\State\StateStore;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * These tests open a real TLS socket. They do not use Http::fake.
 * The addon still verifies certificates; the test adds its CA to the
 * HTTP client's trust bundle so CI does not need a system-wide CA install.
 */
class LiveHookTest extends TestCase
{
    private static ?LiveHookServer $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = LiveHookServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$server->reset();
        Http::globalOptions([
            'verify' => self::$server->caBundle(),
        ]);
    }

    protected function tearDown(): void
    {
        Http::globalOptions([]);

        parent::tearDown();
    }

    public function test_a_hook_publish_hits_a_real_server_with_the_documented_request(): void
    {
        $this->signInSuper();
        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget('hook', [
                'label' => 'Generic hook',
                'hook_url' => self::$server->url('/deploy/secret-path'),
            ]),
        ]);

        $response = $this->postJson('/cp/utilities/ssg-publish/publish', [
            'target' => 'preview',
        ])->assertOk();

        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('state', 'success');
        $this->assertStringNotContainsString('secret-path', $response->getContent());

        $requests = self::$server->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/deploy/secret-path', $requests[0]['path']);
        $this->assertSame('', $requests[0]['body']);
        $this->assertSame('ssg-publish', $requests[0]['headers']['User-Agent'] ?? null);
        $this->assertSame('application/json', $requests[0]['headers']['Accept'] ?? null);
        $this->assertSame('text/plain', $requests[0]['headers']['Content-Type'] ?? null);
        $this->assertSame('0', (string) ($requests[0]['headers']['Content-Length'] ?? ''));
        $this->assertArrayNotHasKey('Authorization', $requests[0]['headers']);

        $status = app(PublishPage::class)->toArray()['targets'][0]['status'];
        $this->assertSame('success', $status['state']);
        $this->assertSame('Accepted', $status['label']);
        $this->assertSame(200, $status['http_status']);
        $this->assertStringContainsString('accepted', strtolower((string) $status['response_excerpt']));
        $this->assertStringNotContainsString('secret-path', json_encode($status));
    }

    public function test_a_real_redirect_is_not_followed(): void
    {
        $this->signInSuper();
        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget('netlify', [
                'hook_url' => self::$server->url('/deploy/redirect'),
            ]),
        ]);

        $this->postJson('/cp/utilities/ssg-publish/publish', [
            'target' => 'preview',
        ])->assertStatus(422)
            ->assertJsonPath('state', 'failure');

        $requests = self::$server->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/deploy/redirect', $requests[0]['path']);
        $this->assertSame('POST', $requests[0]['method']);
    }

    public function test_the_free_edition_does_not_call_a_second_real_target(): void
    {
        $this->signInSuper();
        config()->set('ssg-publish.targets', [
            'production' => $this->hookTarget('cloudflare_pages', [
                'label' => 'First',
                'hook_url' => self::$server->url('/deploy/first-secret'),
            ]),
            'preview' => $this->hookTarget('vercel', [
                'label' => 'Second',
                'hook_url' => self::$server->url('/deploy/second-secret'),
            ]),
        ]);

        $this->postJson('/cp/utilities/ssg-publish/publish', ['target' => 'preview'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That target requires SSG Publish Pro. The free edition publishes the first configured target only.');

        $this->assertSame([], self::$server->requests());

        $this->postJson('/cp/utilities/ssg-publish/publish', ['target' => 'production'])
            ->assertOk();

        $requests = self::$server->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/deploy/first-secret', $requests[0]['path']);
    }

    public function test_a_due_entry_publishes_to_a_real_hook_only_on_pro(): void
    {
        Collection::make('blog')->title('Blog')->dated(true)->save();

        config()->set('ssg-publish.auto_publish_scheduled', true);
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget('cloudflare_pages', [
                'hook_url' => self::$server->url('/deploy/scheduled-secret'),
            ]),
        ]);
        app(StateStore::class)->setScheduledCursor(now()->subHours(2));

        Entry::make()
            ->id('went-live-real')
            ->collection('blog')
            ->slug('went-live-real')
            ->date(now()->subMinutes(30))
            ->data(['title' => 'Went live'])
            ->published(true)
            ->save();

        $this->artisan('ssg-publish:scheduled')
            ->expectsOutputToContain('SSG Publish Pro')
            ->assertSuccessful();
        $this->assertSame([], self::$server->requests());

        $this->useEdition('pro');

        $this->artisan('ssg-publish:scheduled')->assertSuccessful();

        $requests = self::$server->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/deploy/scheduled-secret', $requests[0]['path']);
        $this->assertSame('', $requests[0]['body']);
        $this->assertSame('ssg-publish', $requests[0]['headers']['User-Agent'] ?? null);
    }
}
