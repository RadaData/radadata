<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Service\JobsManager;
use ShvetsGroup\Service\Proxy\ProxyManager;
use Symfony\Component\Console as Console;
use ShvetsGroup\Model\Job;
use Illuminate\Database\Capsule\Manager as DB;

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
     * @var ProxyManager
     */
    private $proxyManager;

    /**
     * @param DiscoverCommand $discoverer
     * @param DownloadCommand $downloader
     * @param JobsManager  $jobsManager
     * @param ProxyManager $proxyManager
     */
    public function __construct($discoverer, $downloader, $jobsManager, $proxyManager)
    {
        parent::__construct('cron');

        $this->setDescription('Cron runner.');
        $this->addOption('single', 's', Console\Input\InputOption::VALUE_NONE, 'Run single instance of the script (old instances will be terminated).');
        $this->addOption('proxy', 'p', Console\Input\InputOption::VALUE_OPTIONAL, 'Whether or not to use proxy servers and how much proxies to create.');
        $this->addOption('kill_old_proxies', 'k', Console\Input\InputOption::VALUE_NONE, 'Kill old proxies and create new.');

        $this->discoverer = $discoverer;
        $this->downloader = $downloader;
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
        if ($input->getOption('single')) {
            $output = [];
            exec('pgrep -l -f "^php console.php"', $output);
            foreach ($output as $line) {
                $pid = preg_replace('|([0-9]+)(\s.*)|', '$1', $line);
                if ($pid != getmypid()) {
                    exec("kill -9 $pid");
                }
            }
        }

        if ($input->getOption('proxy')) {
            $this->workers = $input->getOption('proxy');
            $this->proxyManager->useProxy(true);
        }

        if ($this->proxyManager->useProxy()) {
            $this->proxyManager->connect($this->workers, $input->getOption('kill_old_proxies'));
        }

        if (!$this->jobsManager->count()) {
            _log('No jobs found. Initializing a new discovery and download jobs.');
            $this->discoverer->discoverNewLaws();
            $this->downloader->downloadNewLaws();
        }
        $this->jobsManager->launch($this->workers);
    }
}