<?php

namespace App\Console\Commands;

use App\Models\ClientBuild;
use App\Services\ClientBuilder\GithubBuilder;
use Illuminate\Console\Command;

/**
 * Starts queued Client Builder builds and follows running ones until their
 * installers are in Client Downloads. Scheduled every minute; a build page
 * left open polls the same way, so either one moves a build along.
 */
class PollClientBuilds extends Command
{
    protected $signature = 'cortendesk:poll-client-builds';

    protected $description = 'Start queued client builds and collect finished ones from GitHub';

    public function handle(GithubBuilder $builder): int
    {
        $builds = ClientBuild::query()->whereIn('status', ClientBuild::ACTIVE)->orderBy('id')->get();

        foreach ($builds as $build) {
            $builder->poll($build);
            $this->line('#'.$build->id.' '.$build->fresh()->status);
        }

        return self::SUCCESS;
    }
}
