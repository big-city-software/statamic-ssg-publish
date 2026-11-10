<?php

namespace SegmentHoldings\SsgPublish\Commands;

use Illuminate\Console\Command;
use SegmentHoldings\SsgPublish\Publishing\AutoPublisher;

class ScheduledPublishCommand extends Command
{
    protected $signature = 'ssg-publish:scheduled';

    protected $description = 'Publish the static site when a scheduled entry is due or a debounced save has settled.';

    public function handle(AutoPublisher $publisher): int
    {
        $run = $publisher->run();

        foreach ($run->lines as $line) {
            $this->line($line);
        }

        return $run->exit;
    }
}
