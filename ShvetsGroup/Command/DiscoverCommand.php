<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

class DiscoverCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\Jobs
     */
    private $jobs;

    /**
     * @var \ShvetsGroup\Service\Issuers
     */
    private $issuers;

    private $reset = false;
    private $re_download = false;

    /**
     * @param      \ShvetsGroup\Service\Jobs    $jobs
     * @param      \ShvetsGroup\Service\Issuers $issuers
     */
    public function __construct($jobs, $issuers)
    {
        parent::__construct('discover');

        $this->setDescription('Discover law issuers and their law listings.');
        $this->setHelp('Discover law issuers and their law listings. Usually, you would want to run this at the very beginning and then only run "update" to fetch new laws.');
        $this->addOption('reset', 'r', Console\Input\InputOption::VALUE_NONE, 'Reset the law issuers cache.');
        $this->addOption('download', 'd', Console\Input\InputOption::VALUE_NONE, 'Re-download any page from the live website.');

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
        $this->reset = $input->getOption('reset');
        $this->re_download = $input->getOption('download');

        if ($this->issuers->isEmpty() || $this->reset) {
            $this->discoverMeta();
            $this->addDiscoverIssuersJobs();
        }
        $this->jobs->launch(1, 'discover', 'discover_command', 'discoverIssuer');
        $this->jobs->launch(1, 'discover', 'discover_command', 'discoverLawList');

        return true;
    }

    /**
     * Fill the law issuers list.
     */
    protected function discoverMeta()
    {
        $this->issuers->parse(download('/laws/stru/a', $this->re_download));
    }

    /**
     * Schedule crawls of each issuer pages.
     */
    protected function addDiscoverIssuersJobs()
    {
        $this->jobs->deleteAll('discover');

        $i = 0;
        foreach ($this->issuers->getIssuers() as $issuer) {
            $this->jobs->add('discover_command', 'discoverIssuer', ['issuer_url' => $issuer['url']], 'discover');
            $i++;
        }
        _log($i . ' jobs added.');
    }

    /**
     * Crawl the issuer page. Take the number of law list pages from it and schedule crawls for each of them.
     *
     * @param $issuer_url
     */
    public function discoverIssuer($issuer_url)
    {
        try {
            $first_page = crawler(download($issuer_url, $this->re_download));
            $last_pager_link = $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/span/a[last()]');
            $page_count = $last_pager_link->count() ? preg_replace('/(.*?)([0-9]+)$/', '$2', $last_pager_link->attr('href')) : 1;
            $this->addDiscoverLawListJobs($issuer_url, $page_count);
        } catch (Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }

    /**
     * Schedule crawls of law list pages.
     *
     * @param $issuer_url
     * @param $page_count
     */
    protected function addDiscoverLawListJobs($issuer_url, $page_count)
    {
        $i = 0;
        for ($j = $page_count; $j >= 1; $j--) {
            $this->jobs->add('discover_command', 'discoverLawList', ['law_list_url' => $issuer_url . ($j > 1 ? '/page' . $j : '')], 'discover');
            $i++;
        }
        _log($i . ' jobs added.');
    }

    /**
     * Crawl the law list page. Take all law urls from it and add them to database.
     *
     * @param string $law_list_url Law list URL.
     */
    public function discoverLawList($law_list_url)
    {
        try {
            $list_page = crawler(download($law_list_url, $this->re_download));

            $urls = [];
            $list_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/dl/dd/ol/li/a')
                ->each(
                    function ($node) use (&$urls) {
                        $urls[] = $node->attr('href');
                    }
                );
            mark_law_discovered($urls);
        } catch (Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }
}