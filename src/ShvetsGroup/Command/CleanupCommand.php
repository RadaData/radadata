<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

class CleanupCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\JobsManager
     */
    private $jobsManager;

    /**
     * @param \ShvetsGroup\Service\JobsManager $jobsManager
     */
    public function __construct($jobsManager)
    {
        parent::__construct('cleanup');

        $this->setDescription('Cleanup jobs pool.');

        $this->jobsManager = $jobsManager;
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
        $this->jobsManager->deleteAll();

        return true;
    }
}