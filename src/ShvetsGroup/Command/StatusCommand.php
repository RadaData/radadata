<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

use ShvetsGroup\Service\JobsManager;
use ShvetsGroup\Service\Proxy\ProxyManager;

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
    }
}