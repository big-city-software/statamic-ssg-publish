<?php

namespace SegmentHoldings\SsgPublish\Tests;

use SegmentHoldings\SsgPublish\ServiceProvider;
use SegmentHoldings\SsgPublish\State\StateStore;
use Statamic\Addons\Manifest;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        app(StateStore::class)->forget();
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $manifest = $app->make(Manifest::class);
        $id = 'segment-holdings/statamic-ssg-publish';
        $manifest->manifest[$id]['editions'] = ['free', 'pro'];
        $manifest->manifest[$id]['name'] = 'SSG Publish';
        $manifest->manifest[$id]['slug'] = 'ssg-publish';
    }

    protected function useEdition(string $edition): void
    {
        config()->set('statamic.editions.addons.segment-holdings/statamic-ssg-publish', $edition);
    }

    protected function signInSuper(): void
    {
        $user = User::make()
            ->id('user-super')
            ->email('super@example.com')
            ->makeSuper();

        $user->save();

        $this->actingAs($user);
    }

    protected function githubTarget(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'github_actions',
            'label' => 'Production',
            'owner' => 'segment-holdings',
            'repo' => 'site',
            'workflow' => 'deploy.yml',
            'ref' => 'main',
            'token' => 'ghp_test_token_should_not_leak',
        ], $overrides);
    }

    protected function hookTarget(string $driver = 'cloudflare_pages', array $overrides = []): array
    {
        return array_merge([
            'driver' => $driver,
            'label' => 'Preview',
            'hook_url' => 'https://hooks.example.test/deploy/secret-path',
        ], $overrides);
    }
}
