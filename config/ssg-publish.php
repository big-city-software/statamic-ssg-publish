<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deploy targets
    |--------------------------------------------------------------------------
    |
    | Each target is a place that rebuilds the static site. Put secrets in the
    | environment. They are read on the server and are never sent to the
    | Control Panel.
    |
    | The free edition publishes the first enabled target only. Further targets
    | require the Pro edition. Select the edition in config/statamic/editions.php:
    |
    | 'addons' => [
    |     'segment-holdings/statamic-ssg-publish' => 'pro',
    | ],
    |
    | Drivers: github_actions, cloudflare_pages, netlify, vercel, hook.
    |
    */

    'targets' => [

        // 'production' => [
        //     'driver' => 'github_actions',
        //     'label' => 'Production',
        //     'enabled' => true,
        //     'owner' => env('SSG_PUBLISH_GITHUB_OWNER'),
        //     'repo' => env('SSG_PUBLISH_GITHUB_REPO'),
        //     'workflow' => env('SSG_PUBLISH_GITHUB_WORKFLOW', 'deploy.yml'),
        //     'ref' => env('SSG_PUBLISH_GITHUB_REF', 'main'),
        //     'token' => env('SSG_PUBLISH_GITHUB_TOKEN'),
        //     'inputs' => [],
        //     // 'api_url' => 'https://api.github.com',
        // ],

        // 'cloudflare' => [
        //     'driver' => 'cloudflare_pages',
        //     'label' => 'Cloudflare Pages',
        //     'enabled' => true,
        //     'hook_url' => env('SSG_PUBLISH_CLOUDFLARE_HOOK'),
        // ],

        // 'netlify' => [
        //     'driver' => 'netlify',
        //     'label' => 'Netlify',
        //     'hook_url' => env('SSG_PUBLISH_NETLIFY_HOOK'),
        // ],

        // 'vercel' => [
        //     'driver' => 'vercel',
        //     'label' => 'Vercel',
        //     'hook_url' => env('SSG_PUBLISH_VERCEL_HOOK'),
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Which targets automatic publish should hit
    |--------------------------------------------------------------------------
    |
    | Null means every available target. If a GitHub workflow already deploys
    | to Cloudflare Pages, do not also list a Cloudflare deploy hook or the
    | site will build twice.
    |
    */

    'auto_targets' => null,

    /*
    |--------------------------------------------------------------------------
    | Debounced publish after a save
    |--------------------------------------------------------------------------
    |
    | Pro only. When an entry, term, or global is saved or deleted, wait this
    | many seconds after the last change, then publish. The wait is honored by
    | the scheduler command below. A queue worker can publish sooner when the
    | queue connection is not "sync".
    |
    */

    'auto_publish_on_save' => env('SSG_PUBLISH_AUTO_ON_SAVE', false),

    'debounce_seconds' => (int) env('SSG_PUBLISH_DEBOUNCE', 60),

    /*
    |--------------------------------------------------------------------------
    | Publish when a scheduled entry comes due
    |--------------------------------------------------------------------------
    |
    | Pro only. Future-dated entries become visible in Statamic on their own.
    | A static site still needs a rebuild. The first time this runs it records
    | a baseline and does not rebuild history. Press Publish once after install
    | if the current site is already behind.
    |
    */

    'auto_publish_scheduled' => env('SSG_PUBLISH_AUTO_SCHEDULED', false),

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | When register is true and either auto-publish option is on, the addon
    | adds `ssg-publish:scheduled` to Laravel's scheduler. The site still has
    | to run `php please schedule:run` every minute. Set register to false if
    | you would rather add the command yourself.
    |
    */

    'schedule' => [
        'register' => env('SSG_PUBLISH_SCHEDULE', true),
        'expression' => env('SSG_PUBLISH_SCHEDULE_EXPRESSION', '* * * * *'),
    ],

];
