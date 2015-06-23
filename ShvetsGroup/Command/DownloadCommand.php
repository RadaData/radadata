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

    /**
     * @param \ShvetsGroup\Service\Jobs $jobs
     */
    public function __construct($jobs)
    {
        parent::__construct('download');

        $this->setDescription('Download laws scheduled for download.');
        $this->addOption('reset', 'r', Console\Input\InputOption::VALUE_NONE, 'Reset the download jobs pool and fill it with download jobs for NOT DOWNLOADED laws.');

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

        if ($this->reset) {
            clear_jobs('download');

            $i = 0;
            $result = db('db')->query('SELECT law_id FROM urls WHERE status = ' . NOT_DOWNLOADED . ' ORDER BY id');
            foreach ($result as $row) {
                $this->jobs->add('download_command', 'downloadLaw', ['id' => $row['law_id']], 'download');
                $i++;
            }
            _log($i . ' jobs added.');
        }
        launch_workers(20, 'download');

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
            $html = download('/laws/card/' . $law_id, false, '/laws/show/' . $law_id . '/card');

            if (strpos($html, 'Текст відсутній') !== false || strpos($html, 'Текст документа') === false) {
                mark_law($law_id, DOWNLOADED, NO_TEXT);
            } else {
                $url = '/laws/show/' . $law_id . '/page';
                $html = download($url, false, null, ['required' => ['<div id="article"', '</body>']]);
                while (preg_match('|<a href="?(.*?)"? title="наступна сторінка">наступна сторінка</a>|', $html, $matches)) {
                    $url = urldecode($matches[1]);
                    $html = download($url, false, null, ['required' => ['<div id="article"', '</body>']]);
                }
                mark_law($law_id, DOWNLOADED, HAS_TEXT);
            }
        } catch (Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }
}