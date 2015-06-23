<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

class UpdateCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\Jobs
     */
    private $jobs;

    /**
     * @var \ShvetsGroup\Service\Issuers
     */
    private $issuers;

    /**
     * @param \ShvetsGroup\Service\Jobs    $jobs
     * @param \ShvetsGroup\Service\Issuers $issuers
     */
    public function __construct($jobs, $issuers)
    {
        parent::__construct('update');

        $this->setDescription('Update law listings of existing law issuers.');
        $this->setHelp('The great difference between "discover" and "update" is that "update" scans law listing from the very recent and stops when it finds a discovered law.');

        $this->jobs = $jobs;
        $this->issuers = $issuers;
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
        $this->addUpdateIssuersJobs();
        $this->jobs->launch(4, 'update');

        return true;
    }

    /**
     * Schedule crawls of each new issuer law list page.
     */
    protected function addUpdateIssuersJobs()
    {
        $this->jobs->deleteAll('update');

        $i = 0;
        foreach ($this->issuers->getIssuers() as $issuer) {
            $this->jobs->add('update_command', 'updateIssuer', ['issuer_url' => $issuer['url']], 'update');
            $i++;
        }
        _log($i . ' jobs added.');
    }

    /**
     * Scan law list page for undiscovered laws.
     *
     * @param string $url Law list URL.
     */
    function updateIssuer($url)
    {
        try {
            $first_page = crawler(download($url, true));
            $last_pager_link = $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/span/a[last()]');
            $page_count = $last_pager_link->count() ? preg_replace('/(.*?)([0-9]+)$/', '$2', $last_pager_link->attr('href')) : 1;

            $urls = [];
            $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li/a')
                ->each(
                    function ($node) use (&$urls) {
                        $urls[] = $node->attr('href');
                    }
                );

            $i = 2;
            while (!is_law_discovered($urls[count($urls) - 1]) && $i < $page_count) {
                $list_page = crawler(download($url . '/page' . $i, true));
                $list_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li/a')
                    ->each(
                        function ($node) use (&$urls) {
                            $urls[] = $node->attr('href');
                        }
                    );
                $i++;
            }
            mark_law_discovered($urls);
        } catch (Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }

}