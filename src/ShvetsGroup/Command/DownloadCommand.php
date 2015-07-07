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

        Law::where('status', Law::NOT_DOWNLOADED)->chunk(200, function($laws) {
            foreach ($laws as $law) {
                $this->jobsManager->add('download_command', 'downloadCard', ['id' => $law->id], 'download');
            }
        });
        Law::where('status', Law::DOWNLOADED_CARD)->chunk(200, function($laws) {
            foreach ($laws as $law) {
                $this->jobsManager->add('download_command', 'downloadRevisions', ['id' => $law->id], 'download');
            }
        });
        Law::where('status', Law::DOWNLOADED_REVISIONS)->chunk(200, function($laws) {
            foreach ($laws as $law) {
                $this->jobsManager->add('download_command', 'downloadRelations', ['id' => $law->id], 'download');
            }
        });
    }

    /**
     * Download all law pages (card, revisions, relations).
     *
     * @param string $id Law ID.
     */
    function downloadLaw($id)
    {
        $this->jobsManager->add('download_command', 'downloadCard', ['id' => $id], 'download');
        $this->jobsManager->add('download_command', 'downloadRevisions', ['id' => $id], 'download');
        $this->jobsManager->add('download_command', 'downloadRelations', ['id' => $id], 'download');
    }

    /**
     * Download a specific law's card page.
     *
     * @param string $id Law ID.
     */
    function downloadCard($id)
    {
        try {
            $law = Law::find($id);

            $html = download('/laws/card/' . $id, $this->re_download, '/laws/show/' . $id . '/card');

            // TODO: parse card data

            $law->update(['status' => Law::DOWNLOADED_CARD]);

            if (strpos($html, 'Текст відсутній') !== false || strpos($html, 'Текст документа') === false) {
                $law->update(['has_text' => Law::NO_TEXT]);
            }

        } catch (\Exception $e) {
            _log($e->getMessage(), 'red');
        }
    }

    /**
     * Download a specific law's revision pages.
     *
     * @param string $id Law ID.
     */
    function downloadRevisions($id)
    {
        $law = Law::find($id);

        if (!$law->has_text) {
            $law->update(['status' => Law::DOWNLOADED_REVISIONS]);
            return;
        }

        // TODO: the actual parsing
    }
}