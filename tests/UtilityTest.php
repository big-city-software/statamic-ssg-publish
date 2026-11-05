<?php

namespace SegmentHoldings\SsgPublish\Tests;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use SegmentHoldings\SsgPublish\Widgets\SsgPublishWidget;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class UtilityTest extends TestCase
{
    public function test_the_utility_renders_for_a_user_with_permission(): void
    {
        $this->signInSuper();

        $this->get('/cp/utilities/ssg-publish')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ssg-publish::Publish')
                ->where('edition', 'free')
                ->where('pro', false)
                ->has('targets')
                ->where('changes.enabled', false)
            );
    }

    public function test_the_utility_is_forbidden_without_permission(): void
    {
        $role = Role::make('editor')
            ->title('Editor')
            ->addPermission('access cp');
        $role->save();

        $user = User::make()
            ->id('user-editor')
            ->email('editor@example.com')
            ->assignRole($role);
        $user->save();

        $this->actingAs($user)
            ->get('/cp/utilities/ssg-publish')
            ->assertRedirect(cp_route('index'));

        $this->actingAs($user)
            ->postJson('/cp/utilities/ssg-publish/publish', ['target' => 'production'])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_open_the_utility(): void
    {
        $this->get('/cp/utilities/ssg-publish')->assertRedirect();
    }

    public function test_the_widget_hides_the_publish_action_without_permission(): void
    {
        $role = Role::make('author')->title('Author')->addPermission('access cp');
        $role->save();

        $user = User::make()->id('user-author')->email('author@example.com')->assignRole($role);
        $user->save();
        $this->actingAs($user);

        $widget = new SsgPublishWidget;
        $payload = $widget->component()->toArray();

        $this->assertSame('ssg-publish-widget', $payload['name']);
        $this->assertFalse($payload['props']['authorized']);
    }

    public function test_the_free_utility_does_not_call_github_or_leak_the_token(): void
    {
        Http::fake();
        $this->signInSuper();
        config()->set('ssg-publish.targets', [
            'production' => $this->githubTarget(),
        ]);

        $response = $this->get('/cp/utilities/ssg-publish')->assertOk();

        Http::assertNothingSent();
        $response->assertDontSee('ghp_test_token_should_not_leak', false);
    }
}
