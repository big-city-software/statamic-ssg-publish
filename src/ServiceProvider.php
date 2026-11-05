<?php

namespace SegmentHoldings\SsgPublish;

use SegmentHoldings\SsgPublish\Http\Controllers\PublishController;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/addon.js',
            'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function bootAddon()
    {
        Utility::extend(function () {
            Utility::register('ssg_publish')
                ->title('SSG Publish')
                ->navTitle('SSG Publish')
                ->description('Publish the static site from the control panel.')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M3.4 11.2 20.4 3.4c.7-.3 1.4.4 1.1 1.1l-7.8 17.1c-.3.7-1.3.6-1.5-.1l-2-6.1-6.1-2c-.7-.3-.8-1.2-.7-1.2z"/></svg>')
                ->inertia('ssg-publish::Publish', fn () => app(Http\PublishPage::class)->toArray())
                ->routes(function ($router) {
                    $router->post('publish', [PublishController::class, 'store'])->name('publish');
                    $router->get('status', [PublishController::class, 'status'])->name('status');
                });
        });
    }

    protected function schedule($schedule)
    {
        $config = AddonConfig::all();
        $register = (bool) data_get($config, 'schedule.register', true);
        $automatic = (bool) data_get($config, 'auto_publish_scheduled', false)
            || (bool) data_get($config, 'auto_publish_on_save', false);

        if (! $register || ! $automatic) {
            return;
        }

        $expression = (string) data_get($config, 'schedule.expression', '* * * * *');

        if ($expression === '') {
            $expression = '* * * * *';
        }

        $schedule->command('ssg-publish:scheduled')
            ->cron($expression)
            ->withoutOverlapping(5)
            ->name('ssg-publish-scheduled');
    }
}
