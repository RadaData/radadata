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
     * @var \ShvetsGroup\Service\Meta
     */
    private $meta;

    private $reset = false;
    private $re_download = false;

    /**
     * @param \ShvetsGroup\Service\Jobs $jobs
     * @param \ShvetsGroup\Service\Meta $meta
     */
    public function __construct($jobs, $meta)
    {
        parent::__construct('discover');

        $this->setDescription('Discover law issuers and their law listings.');
        $this->setHelp('Discover law issuers and their law listings. Usually, you would want to run this at the very beginning and then only run "update" to fetch new laws.');
        $this->addOption('reset', 'r', Console\Input\InputOption::VALUE_NONE, 'Reset the law issuers cache.');
        $this->addOption('download', 'd', Console\Input\InputOption::VALUE_NONE, 'Re-download any page from the live website.');

        $this->jobs = $jobs;
        $this->meta = $meta;
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

        if ($this->meta->isEmpty() || $this->reset) {
            $this->meta->parse($this->re_download);
            $this->addLawListJobs();
        }
        $this->jobs->launch(5, 'discover', 'discover_command', 'discoverDailyLawList');
        $this->jobs->launch(5, 'discover', 'discover_command', 'discoverDailyLawListPage');

        return true;
    }

    /**
     * Schedule crawls of each law list pages.
     */
    protected function addLawListJobs()
    {
        $this->jobs->deleteAll('discover');

        $this->jobs->add('discover_command', 'discoverDailyLawList', ['law_list_url' => '/laws/main/ay1990/page'], 'discover');

        $date = mktime(0, 0, 0, 1, 1, 1991);
        while ($date < strtotime('midnight')) {
            $this->jobs->add('discover_command', 'discoverDailyLawList', ['law_list_url' => '/laws/main/a' . date('Ymd', $date) . '/sp5/page'], 'discover');
            $date = strtotime(date('c', $date) . '+1 day');
        }
    }

    /**
     * Crawl the daily law list page. Take the number of law list pages from it and schedule crawls for each of them.
     *
     * @param string $law_list_url
     */
    public function discoverDailyLawList($law_list_url)
    {
        try {
            $first_page = crawler(download($law_list_url, $this->re_download));
            $last_pager_link = $first_page->filterXPath('//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/span/a[last()]');
            $page_count = $last_pager_link->count() ? preg_replace('/(.*?)([0-9]+)$/', '$2', $last_pager_link->attr('href')) : 1;
            for ($i = 1; $i <= $page_count; $i++) {
                $this->jobs->add('discover_command', 'discoverDailyLawListPage', ['law_list_url' => $law_list_url . ($i > 1 ? '/page' . $i : ''), $i], 'discover');
            }
        } catch (Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }

    /**
     * Crawl the law list page. Take all law urls from it and add them to database.
     *
     * @param string $law_list_url Law list URL.
     */
    public function discoverDailyLawListPage($law_list_url, $page_num)
    {
        try {
            $list_page = crawler(download($law_list_url, $page_num > 1 ? $this->re_download : false));

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