<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

use ShvetsGroup\Service\JobsManager;
use ShvetsGroup\Service\Proxy\ProxyManager;
use ShvetsGroup\Model\Laws\Law;
use ShvetsGroup\Model\Laws\Revision;
use ShvetsGroup\Model\Job;
use Illuminate\Database\Capsule\Manager as DB;

class StatusCommand extends Console\Command\Command
{
    /**
     * @var JobsManager
     */
    private $jobsManager;

    /**
     * @var ProxyManager
     */
    private $proxyManager;

    /**
     * @param string   $downloadsDir
     * @param JobsManager $jobsManager
     * @param ProxyManager $proxyManager
     */
    public function __construct($downloadsDir, $jobsManager, $proxyManager)
    {
        parent::__construct('status');

        $this->setDescription('Get the full status report.');

        $this->downloadsDir = BASE_PATH . $downloadsDir;
        $this->jobsManager = $jobsManager;
        $this->proxyManager = $proxyManager;
    }

    /**
     * Execute console command.
     *
     * @param Console\Input\InputInterface   $input
     * @param Console\Output\OutputInterface $output
     *
     * @return bool
     * @throws \Exception
     */
    protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output)
    {
        DB::beginTransaction();

        $discovered_count = Law::count();
        $most_recent = Law::orderBy('date', 'desc')->take(1)->value('date');
        $most_recent_diff = floor((time() - (strtotime($most_recent)))/3600/24);
        $most_recent_age = $most_recent_diff ? $most_recent_diff . ' days ago' : 'up to date';

        $cards_downloaded = Law::where('status', Law::DOWNLOADED_CARD)->count();
        $cards_downloaded_p = round(($cards_downloaded / ($discovered_count ?: ($cards_downloaded ?: 1))) * 100);
        $revisions_count = Revision::where('status', '<', Revision::NO_TEXT)->count();
        $revisions_downloaded = Revision::where('status', Revision::UP_TO_DATE)->count();
        $revisions_downloaded_p = round(($revisions_downloaded / ($revisions_count ?: ($revisions_downloaded ?: 1))) * 100);

        $jobs_count = Job::where('finished', 0)->count();
        $jobs_last_10_minutes = Job::where('finished', '>', time() - 600)->count();
        $jobs_last_hour = Job::where('finished', '>', time() - 3600)->count();
        $jobs_last_day = Job::where('finished', '>', time() - 3600 * 24)->count();

        if ($jobs_count) {
            if ($jobs_last_hour) {
                $jobs_completion_time = round($jobs_count / ($jobs_last_10_minutes * 6));
                if ($jobs_completion_time == 0) {
                    $jobs_completion_time = '(estimated finish time: less than hour)';
                }
                else {
                    $jobs_completion_time = '(estimated finish time: ' . $jobs_completion_time . ' hours)';
                }
            }
            else {
                $jobs_completion_time = '(no progress)';
            }
        }
        else {
            $jobs_completion_time = '';
        }

        $output = [];
        exec('pgrep -l -f "^php console.php"', $output);
        if (count($output)) {
            $currently_running = 'RUNNING';
        }
        else {
            $currently_running = 'IDLE';
        }

        $jobs_discovery = Job::where('finished', 0)->where('group', 'discover')->count();
        $jobs_download_cards = Job::where('finished', 0)->where('method', 'downloadCard')->count();
        $jobs_download_revisions = Job::where('finished', 0)->where('method', 'downloadRevisions')->count();

        DB::commit();


        $status = <<<STATUS
=== Discovered laws: {$discovered_count}
    Most recent law: {$most_recent} ({$most_recent_age})

=== Downloaded:
         Cards: {$cards_downloaded} / {$discovered_count} ({$cards_downloaded_p}%)
     Revisions: {$revisions_downloaded} / {$revisions_count} ({$revisions_downloaded_p}%)

=== Jobs: {$currently_running}
    Todo: {$jobs_count} {$jobs_completion_time}

    Discovery jobs: {$jobs_discovery}
    Download cards: {$jobs_download_cards}
    Download revisions: {$jobs_download_revisions}

    Done last 10 minutes: {$jobs_last_10_minutes}
    Done last hour: {$jobs_last_hour}
    Done last day: {$jobs_last_day}

STATUS;

        print($status);
    }
}