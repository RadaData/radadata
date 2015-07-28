<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Model\Laws;
use ShvetsGroup\Model\Laws\Law;
use Symfony\Component\Console as Console;
use Illuminate\Database\Capsule\Manager as DB;
use ShvetsGroup\Service\Exceptions;

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

        Law::where('status', '<', Law::DOWNLOADED_CARD)->where('date', '<', max_date())->chunk(200, function ($laws) {
            foreach ($laws as $law) {
                $this->jobsManager->add('download_command', 'downloadCard', [
                    'id'          => $law->id,
                    're_download' => $law->status == Law::DOWNLOADED_BUT_NEEDS_UPDATE
                ], 'download', 1);
            }
        });
        Laws\Revision::where('status', Laws\Revision::NEEDS_UPDATE)->chunk(200, function ($revisions) {
            foreach ($revisions as $revision) {
                $law = Law::find($revision->law_id);
                $this->jobsManager->add('download_command', 'downloadRevision', [
                    'law_id' => $revision->law_id,
                    'date'   => $revision->date,
                ], 'download', $law->active_revision == $revision->date ? 0 : -1);
            }
        });
    }

    /**
     * Download a specific law's card page.
     *
     * @param string $id          Law ID.
     * @param bool   $re_download Whether or not to re-download card page.
     *
     * @return Law
     */
    function downloadCard($id, $re_download = false)
    {
        /**
         * @var $law Law
         */
        $law = Law::find($id);

        try {
            $card = downloadCard($id, [
                're_download'   => $re_download || $this->re_download,
                'check_related' => $law->status == Law::NOT_DOWNLOADED && !max_date()
            ]);
        } catch (\Exception $e) {
            throw new Exceptions\JobChangePriorityException(-5);
        }

        DB::transaction(function () use ($law, $card) {
            $law->card = $card['html'];

            $law->issuers()->sync($card['meta'][Laws\Issuer::FIELD_NAME]);
            $law->types()->sync($card['meta'][Laws\Type::FIELD_NAME]);
            $law->state = isset($card['meta'][Laws\State::FIELD_NAME]) ? reset($card['meta'][Laws\State::FIELD_NAME]) : Laws\State::STATE_UNKNOWN;

            $law->has_text = $card['has_text'] ? $law->has_text = Law::HAS_TEXT : $law->has_text = Law::NO_TEXT;

            foreach ($card['revisions'] as $date => &$revision) {
                $data = [
                    'law_id'  => $revision['law_id'],
                    'date'    => $revision['date'],
                    'comment' => $revision['comment']
                ];
                // We should be careful with statuses, since we don't want to re-download already downloaded revisions.
                if (isset($revision['no_text']) && $revision['no_text']) {
                    $data['status'] = Laws\Revision::NO_TEXT;
                }
                if (isset($revision['needs_update']) && $revision['needs_update']) {
                    $data['status'] = Laws\Revision::NEEDS_UPDATE;
                }
                Laws\Revision::updateOrCreate($data);
            }
            $law->active_revision = $card['active_revision'];

            foreach ($law->revisions()->where('status', Laws\Revision::NEEDS_UPDATE)->get() as $revision) {
                $this->jobsManager->add('download_command', 'downloadRevision', [
                    'law_id' => $revision->law_id,
                    'date'   => $revision->date,
                ], 'download', $revision->date == $law->active_revision ? 0 : -1);
            }

            if (isset($card['changes_laws']) && $card['changes_laws']) {
                Law::where('id', array_column($card['changes_laws'], 'id'))->update(['status' => Law::DOWNLOADED_BUT_NEEDS_UPDATE]);
                foreach ($card['changes_laws'] as $l) {
                    $this->jobsManager->add('download_command', 'downloadCard', [
                        'id'          => $l['id'],
                        're_download' => true
                    ], 'download', 2);
                }
            }

            $law->card_updated = $card['timestamp'];

            $law->status = Law::DOWNLOADED_CARD;

            $law->save();
        });

        return $law;
    }

    /**
     * Download a specific law's revision pages.
     *
     * @param string $law_id
     * @param string $date
     * @param bool   $re_download Whether or not to re-download card page.
     */
    function downloadRevision($law_id, $date, $re_download = false)
    {
        $law = Law::find($law_id);
        $revision = $law->getRevision($date);

        if ($revision->status != Laws\Revision::NEEDS_UPDATE && !$re_download) {
            return $revision;
        }

        try {
            $data = downloadRevision($revision->law_id, $revision->date, ['re_download' => $re_download]);
        } catch (Exceptions\RevisionDateNotFound $e) {
            $this->downloadCard($law->id, true);
            $data = downloadRevision($revision->law_id, $revision->date, ['re_download' => $re_download]);
        } catch (\Exception $e) {
            throw new Exceptions\JobChangePriorityException(-10);
        }

        DB::transaction(function () use ($law, $revision, $data) {
            $revision->update([
                'text'         => $data['text'],
                'text_updated' => $data['timestamp'],
                'status'       => Laws\Revision::UP_TO_DATE
            ]);
        });

        return $revision;
    }
}