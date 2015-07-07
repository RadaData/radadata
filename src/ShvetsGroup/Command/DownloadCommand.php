<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Model\Law;
use Symfony\Component\Console as Console;

class DownloadCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\JobsManager
     */
    private $jobsManager;

    private $reset = false;

    private $re_download = false;

    /**
     * @param \ShvetsGroup\Service\JobsManager $jobsManager
     */
    public function __construct($jobsManager)
    {
        parent::__construct('download');

        $this->setDescription('Download laws scheduled for download.');
        $this->addOption('reset', 'r', Console\Input\InputOption::VALUE_NONE, 'Reset the download jobs pool and fill it with download jobs for NOT DOWNLOADED laws.');
        $this->addOption('download', 'd', Console\Input\InputOption::VALUE_NONE, 'Re-download any page from the live website.');

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
        $this->reset = $input->getOption('reset');
        $this->re_download = $input->getOption('download');

        if ($this->reset) {
            $this->downloadNewLaws();
        }
        $this->jobsManager->launch(50, 'download');

        return true;
    }

    /**
     * Reset download jobs.
     */
    function downloadNewLaws()
    {
        $this->jobsManager->deleteAll('download');

        // TODO: CHUNKS
        foreach (Law::where('status', Law::NOT_DOWNLOADED)->get() as $law) {
            $this->jobsManager->add('download_command', 'downloadCard', ['law_id' => $law->law_id], 'download');
        }

        foreach (Law::where('status', Law::DOWNLOADED_CARD)->get() as $law) {
            $this->jobsManager->add('download_command', 'downloadRevisions', ['law_id' => $law->law_id], 'download');
        }

        foreach (Law::where('status', Law::DOWNLOADED_REVISIONS)->get() as $law) {
            $this->jobsManager->add('download_command', 'downloadRelations', ['law_id' => $law->law_id], 'download');
        }
    }

    /**
     * Download all law pages (card, revisions, relations).
     *
     * @param string $law_id Law ID.
     */
    function downloadLaw($law_id)
    {
        $this->jobsManager->add('download_command', 'downloadCard', ['id' => $law_id], 'download');
        $this->jobsManager->add('download_command', 'downloadRevisions', ['id' => $law_id], 'download');
        $this->jobsManager->add('download_command', 'downloadRelations', ['id' => $law_id], 'download');
    }

    /**
     * Download a specific law's card page.
     *
     * @param string $law_id Law ID.
     */
    function downloadCard($law_id)
    {
        try {
            $html = download('/laws/card/' . $law_id, $this->re_download, '/laws/show/' . $law_id . '/card');

            // TODO: parse card data

            Law::find($law_id)->update(['status' => Law::DOWNLOADED_CARD]);

            if (strpos($html, 'Текст відсутній') !== false || strpos($html, 'Текст документа') === false) {
                Law::find($law_id)->update(['has_text' => Law::NO_TEXT]);
            }

        } catch (\Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }

    /**
     * Download a specific law's revision pages.
     *
     * @param string $law_id Law ID.
     */
    function downloadRevisions($law_id)
    {
        if (!Law::find($law_id)->has_text) {
            Law::find($law_id)->update(['status' => Law::DOWNLOADED_REVISIONS]);
            return;
        }

        // TODO: the actual parsing
    }
}