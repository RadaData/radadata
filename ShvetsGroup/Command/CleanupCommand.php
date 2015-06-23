<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

class CleanupCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\Jobs
     */
    private $jobs;

    /**
     * @param \ShvetsGroup\Service\Jobs $jobs
     */
    public function __construct($jobs)
    {
        parent::__construct('cleanup');

        $this->setDescription('Cleanup jobs pool.');

        $this->jobs = $jobs;
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
        $this->jobs->cleanup();

        return true;
    }
}