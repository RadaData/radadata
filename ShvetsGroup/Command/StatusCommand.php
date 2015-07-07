<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

class StatusCommand extends Console\Command\Command
{
    /**
     * @var \ShvetsGroup\Service\JobsManager
     */
    private $jobsManager;

    /**
     * @param string   $downloadsDir
     * @param \ShvetsGroup\Service\JobsManager $jobsManager
     * @param \ShvetsGroup\Service\Proxy $proxy
     */
    public function __construct($downloadsDir, $jobsManager, $proxy)
    {
        parent::__construct('status');

        $this->setDescription('Get the full status report.');

        $this->downloadsDir = BASE_PATH . $downloadsDir;
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
    }
}