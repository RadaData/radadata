<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Service\JobsManager;
use ShvetsGroup\Service\Proxy;
use Symfony\Component\Console as Console;

class CronCommand extends Console\Command\Command
{
    /**
     * @var int
     */
    private $workers = 20;

    /**
     * @var DiscoverCommand
     */
    private $discoverer;

    /**
     * @var DownloadCommand
     */
    private $downloader;

    /**
     * @var JobsManager
     */
    private $jobsManager;

    /**
     * @var Proxy
     */
    private $proxy;

    /**
     * @param DiscoverCommand $discoverer
     * @param DownloadCommand $downloader
     * @param JobsManager  $jobsManager
     * @param Proxy $proxy
     */
    public function __construct($discoverer, $downloader, $jobsManager, $proxy)
    {
        parent::__construct('cron');

        $this->setDescription('Cron runner.');
        $this->addOption('single', 's', Console\Input\InputOption::VALUE_NONE, 'Run single instance of the script (old instances will be terminated).');

        $this->discoverer = $discoverer;
        $this->downloader = $downloader;
        $this->jobsManager = $jobsManager;
        $this->proxy = $proxy;
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
        if ($input->getOption('single')) {
            exec('pkill -f " console.php cron"');
        }

        //if ($this->proxy->useProxy()) {
        //    // Cron command should re-launch new proxies, because existing proxies might be already banned by rada since
        //    // the last run.
        //    $this->proxy->killAll();
        //    $this->proxy->makeProxiesOrDie($this->workers);
        //}

        if (!$this->jobsManager->count()) {
            $this->discoverer->discoverNewLaws();
            $this->downloader->downloadNewLaws();
            // Download cards
            // Download revisions
            // Download relations
            // Dump files to DB
            // Parse cards
        }
        $this->jobsManager->launch($this->workers);
    }
}