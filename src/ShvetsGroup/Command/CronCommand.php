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
    private $workers = 10;

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
        $this->addOption('proxy', 'p', Console\Input\InputOption::VALUE_OPTIONAL, 'Whether or not to use proxy servers and how much proxies to create.');
        $this->addOption('kill_old_proxies', 'k', Console\Input\InputOption::VALUE_OPTIONAL, 'Kill old proxies and create new.');

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

        if ($input->getOption('proxy')) {
            $this->workers = $input->getOption('proxy');
            $this->proxy->useProxy(true);
        }

        if ($this->proxy->useProxy()) {
            if ($input->getOption('kill_old_proxies')) {
                $this->proxy->killAll();
            }
            $this->proxy->makeProxiesOrDie($this->workers);
        }

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