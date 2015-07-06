<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Service\Jobs;
use ShvetsGroup\Service\Proxy;
use Symfony\Component\Console as Console;

class CronCommand extends Console\Command\Command
{
    /**
     * @var int
     */
    private $workers = 1;

    /**
     * @var DiscoverCommand
     */
    private $discoverer;

    /**
     * @var DownloadCommand
     */
    private $downloader;

    /**
     * @var Jobs
     */
    private $jobs;

    /**
     * @var Proxy
     */
    private $proxy;

    /**
     * @param DiscoverCommand $discoverer
     * @param DownloadCommand $downloader
     * @param Jobs  $jobs
     * @param Proxy $proxy
     */
    public function __construct($discoverer, $downloader, $jobs, $proxy)
    {
        parent::__construct('cron');

        $this->setDescription('Cron runner.');

        $this->discoverer = $discoverer;
        $this->downloader = $downloader;
        $this->jobs = $jobs;
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
        if (variable_get('use_proxy')) {
            // Cron command should re-launch new proxies, because existing proxies might be already banned by rada since
            // the last run.
            $this->proxy->killAll();
            $this->proxy->makeProxiesOrDie($this->workers);
        }

        if (!$this->jobs->count()) {
            $this->discoverer->discoverNewLaws();
            $this->downloader->downloadNewLaws();
            // Download cards
            // Download revisions
            // Download relations
            // Dump files to DB
            // Parse cards
        }
        $this->jobs->launch($this->workers);
    }
}