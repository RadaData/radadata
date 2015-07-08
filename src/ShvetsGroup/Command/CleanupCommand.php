<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;
use ShvetsGroup\Service\JobsManager;
use ShvetsGroup\Service\Proxy;

class CleanupCommand extends Console\Command\Command
{

    /**
     * @var JobsManager
     */
    private $jobsManager;

    /**
     * @var Proxy
     */
    private $proxy;

    /**
     * @param JobsManager $jobsManager
     * @param Proxy $proxy
     */
    public function __construct($jobsManager, $proxy)
    {
        parent::__construct('cleanup');

        $this->setDescription('Cleanup jobs pool.');
        $this->addOption('all', 'a', Console\Input\InputOption::VALUE_NONE, 'Kill all proxies and jobs.');
        $this->addOption('proxy', 'p', Console\Input\InputOption::VALUE_NONE, 'Kill all proxies.');
        $this->addOption('jobs', 'j', Console\Input\InputOption::VALUE_NONE, 'Flush all jobs.');

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
        if ($input->getOption('jobs') || $input->getOption('all')) {
            $this->jobsManager->deleteAll();
        }
        if ($input->getOption('proxy') || $input->getOption('all')) {
            $this->proxy->killAll();
        }

        return true;
    }
}