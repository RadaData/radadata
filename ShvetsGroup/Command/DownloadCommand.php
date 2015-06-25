<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

class DownloadCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\Jobs
     */
    private $jobs;

    private $reset = false;
    private $re_download = false;

    /**
     * @param \ShvetsGroup\Service\Jobs $jobs
     */
    public function __construct($jobs)
    {
        parent::__construct('download');

        $this->setDescription('Download laws scheduled for download.');
        $this->addOption('reset', 'r', Console\Input\InputOption::VALUE_NONE, 'Reset the download jobs pool and fill it with download jobs for NOT DOWNLOADED laws.');
        $this->addOption('download', 'd', Console\Input\InputOption::VALUE_NONE, 'Re-download any page from the live website.');

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
        $this->reset = $input->getOption('reset');
        $this->re_download = $input->getOption('download');

        if ($this->reset) {
            $this->jobs->deleteAll('download');

            $i = 0;
            $result = db('db')->query('SELECT law_id FROM laws WHERE status = ' . NOT_DOWNLOADED . ' ORDER BY law_id');
            foreach ($result as $row) {
                $this->jobs->add('download_command', 'downloadLaw', ['id' => $row['law_id']], 'download');
                $i++;
            }
            _log($i . ' jobs added.');
        }
        $this->jobs->cleanup();
        $this->jobs->launch(50, 'download');

        return true;
    }

    /**
     * Download a specific law page.
     *
     * @param string $law_id Law ID.
     */
    function downloadLaw($law_id)
    {
        try {
            $html = download('/laws/card/' . $law_id, $this->re_download, '/laws/show/' . $law_id . '/card');

            mark_law($law_id, DOWNLOADED_CARD);

            if (strpos($html, 'Текст відсутній') !== false || strpos($html, 'Текст документа') === false) {
                mark_law($law_id, DOWNLOADED_CARD, NO_TEXT);
            }

            //else {
            //    $url = '/laws/show/' . $law_id . '/page';
            //    $html = download($url, false, null, ['required' => ['<div id="article"', '</body>']]);
            //    while (preg_match('|<a href="?(.*?)"? title="наступна сторінка">наступна сторінка</a>|', $html, $matches)) {
            //        $url = urldecode($matches[1]);
            //        $html = download($url, false, null, ['required' => ['<div id="article"', '</body>']]);
            //    }
            //    mark_law($law_id, DOWNLOADED_REVISIONS, HAS_TEXT);
            //}
        } catch (Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }
}