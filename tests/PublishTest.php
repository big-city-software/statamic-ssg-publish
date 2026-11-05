<?php

namespace SegmentHoldings\SsgPublish\Tests;

use Illuminate\Support\Facades\Http;
use SegmentHoldings\SsgPublish\Http\PublishPage;

class PublishTest extends TestCase
{
    public function test_github_dispatch_stays_on_the_server(): void
    {
        Http::fake([
            'https://api.github.com/*' => Http::response('', 204),
        ]);

        $this->signInSuper();
        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(['inputs' => ['environment' => 'production']]),
        ]);

        $response = $this->postJson('/cp/utilities/ssg-publish/publish', [
            'target' => 'production',
        ])->assertOk();

        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('state', 'queued');
        $this->assertStringNotContainsString('ghp_test_token_should_not_leak', $response->getContent());

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.github.com/repos/segment-holdings/site/actions/workflows/deploy.yml/dispatches'
                && $request->hasHeader('Authorization', 'Bearer ghp_test_token_should_not_leak')
                && $request['ref'] === 'main'
                && $request['inputs']['environment'] === 'production';
        });
    }

    public function test_github_rate_limits_are_explained(): void
    {
        $reset = now()->addMinutes(12)->timestamp;

        Http::fake([
            'https://api.github.com/*' => Http::response([
                'message' => 'API rate limit exceeded for ghp_test_token_should_not_leak',
            ], 403, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) $reset,
            ]),
        ]);

        $this->signInSuper();
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
        ]);

        $response = $this->postJson('/cp/utilities/ssg-publish/publish', [
            'target' => 'production',
        ])->assertStatus(422);

        $response->assertJsonPath('state', 'rate_limited');
        $this->assertStringContainsString('GitHub rate limit reached', $response->json('message'));
        $this->assertStringNotContainsString('ghp_test_token_should_not_leak', $response->getContent());
    }

    public function test_a_deploy_hook_posts_without_exposing_the_url(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response('failed at https://hooks.example.test/deploy/secret-path', 500),
        ]);

        $this->signInSuper();
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget(),
        ]);

        $response = $this->postJson('/cp/utilities/ssg-publish/publish', [
            'target' => 'preview',
        ])->assertStatus(422);

        $this->assertStringNotContainsString('secret-path', $response->getContent());
        $this->assertStringContainsString('HTTP 500', $response->json('message'));

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://hooks.example.test/deploy/secret-path';
        });
    }

    public function test_a_successful_hook_records_the_response_for_pro(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response(['queued' => true], 200),
        ]);

        $this->signInSuper();
        $this->useEdition('pro');
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget('netlify'),
        ]);

        $this->postJson('/cp/utilities/ssg-publish/publish', ['target' => 'preview'])->assertOk();

        $page = app(PublishPage::class)->toArray();
        $status = $page['targets'][0]['status'];

        $this->assertSame('success', $status['state']);
        $this->assertSame(200, $status['http_status']);
        $this->assertNotNull($status['triggered_at']);
        $this->assertStringNotContainsString('secret-path', json_encode($page));
    }

    public function test_the_free_edition_refuses_a_second_target(): void
    {
        Http::fake();
        $this->signInSuper();
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
            'preview' => $this->hookTarget(),
        ]);

        $this->postJson('/cp/utilities/ssg-publish/publish', ['target' => 'preview'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That target requires SSG Publish Pro. The free edition publishes the first configured target only.');

        Http::assertNothingSent();
    }

    public function test_an_unconfigured_target_does_not_call_out(): void
    {
        Http::fake();
        $this->signInSuper();
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(['token' => null]),
        ]);

        $this->postJson('/cp/utilities/ssg-publish/publish', ['target' => 'production'])
            ->assertStatus(422)
            ->assertSee('token', false);

        Http::assertNothingSent();
    }

    public function test_an_http_hook_is_rejected(): void
    {
        Http::fake();
        $this->signInSuper();
        config()->set('ssg-publish.targets', [
            'preview' => $this->hookTarget('vercel', [
                'hook_url' => 'http://hooks.example.test/insecure',
            ]),
        ]);

        $this->postJson('/cp/utilities/ssg-publish/publish', ['target' => 'preview'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
