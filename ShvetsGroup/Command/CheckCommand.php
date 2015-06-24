<?php

namespace ShvetsGroup\Command;

use Symfony\Component\Console as Console;

class CheckCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\Jobs
     */
    private $jobs;

    /**
     * @param string   $downloadsDir
     * @param \ShvetsGroup\Service\Jobs $jobs
     */
    public function __construct($downloadsDir, $jobs)
    {
        parent::__construct('check');

        $this->setDescription('Cleanup jobs pool.');
        $this->addOption('fix', 'f', Console\Input\InputOption::VALUE_NONE, 'Try to fix all problems.');

        $this->downloadsDir = BASE_PATH . $downloadsDir;
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
        $fix = $input->getOption('fix');

        $downloaded_card = db('db')->query("SELECT COUNT(*) FROM laws WHERE status = " . DOWNLOADED_CARD)->fetchColumn();
        $downloaded_text = db('db')->query("SELECT COUNT(*) FROM laws WHERE status = " . DOWNLOADED_REVISIONS)->fetchColumn();
        $downloaded_relations = db('db')->query("SELECT COUNT(*) FROM laws WHERE status = " . DOWNLOADED_RELATIONS)->fetchColumn();
        $without_text = db('db')->query("SELECT COUNT(*) FROM laws WHERE status > " . NOT_DOWNLOADED . " AND has_text = " . NO_TEXT)->fetchColumn();
        $not_downloaded = db('db')->query("SELECT COUNT(*) FROM laws WHERE status = " . NOT_DOWNLOADED)->fetchColumn();

        $result_count = db('db')->query('SELECT COUNT(*) FROM laws WHERE status < ' . SAVED)->fetchColumn();
        $result = db('db')->query('SELECT law_id, status, has_text FROM laws WHERE status < ' . SAVED . ' ORDER BY law_id');
        $law_dir = $this->downloadsDir . '/zakon.rada.gov.ua/laws/show/';

        function is_fake($html, $is_text = true)
        {
            return downloader()->detectFakeContent($html, [
                'required' => ['<div id="article"', '</body>'],
                'stop'     => $is_text ? ['<div id="pan_title"'] : null
            ]);
        }

        function remove_dir($dir)
        {
            exec('rm -rf ' . $dir);
        }

        $nd_orphaned_dirs = 0;
        $d_no_files = 0;
        $d_fake_content = 0;
        $d_unknown_text_true_content = 0;
        $d_unknown_text_no_text = 0;

        $i = 1;
        foreach ($result as $row) {
            $law_id = $row['law_id'];
            $law_path = $law_dir . $law_id;
            $card_path = $law_dir . $law_id . '/card.html';
            $text_path = $law_dir . $law_id . '/text.html';
            $page_path = $law_dir . $law_id . '/page.html';

            if ($row['status'] == NOT_DOWNLOADED && is_dir($law_path)) {
                $nd_orphaned_dirs++;
                if ($fix) {
                    remove_dir($law_path);
                }
                continue;
            }

            if ($row['status'] > NOT_DOWNLOADED && $row['has_text'] == HAS_TEXT && !file_exists($text_path) && !file_exists($page_path)) {
                $d_no_files++;
                if ($fix) {
                    remove_dir($law_path);
                    mark_law($law_id, NOT_DOWNLOADED);
                }
            }
            if ($row['status'] > NOT_DOWNLOADED && $row['has_text'] == HAS_TEXT && (file_exists($text_path) || file_exists($page_path))) {
                if ((file_exists($text_path) && is_fake(file_get_contents($text_path), 1)) || (file_exists($page_path) && is_fake(file_get_contents($page_path), 0))) {
                    $d_fake_content++;
                    if ($fix) {
                        remove_dir($law_path);
                        mark_law($law_id, NOT_DOWNLOADED);
                    }
                }
            }

            if ($row['status'] > NOT_DOWNLOADED && $row['has_text'] == UNKNOWN && (file_exists($text_path) || file_exists($page_path))) {
                if ((file_exists($text_path) && is_fake(file_get_contents($text_path), 1)) || (file_exists($page_path) && is_fake(file_get_contents($page_path), 0))) {
                    $d_fake_content++;
                    if ($fix) {
                        remove_dir($law_path);
                        mark_law($law_id, NOT_DOWNLOADED);
                    }
                }
            }

            if ($row['status'] > NOT_DOWNLOADED && $row['has_text'] == UNKNOWN && !(file_exists($text_path) || file_exists($page_path)) && file_exists($card_path)) {
                $html = file_get_contents($card_path);
                if (strpos($html, 'Текст відсутній') !== false) {
                    $d_unknown_text_no_text++;
                    if ($fix) {
                        mark_law($law_id, DOWNLOADED_CARD, NO_TEXT);
                    }
                } else {
                    $d_no_files++;
                    if ($fix) {
                        mark_law($law_id, NOT_DOWNLOADED);
                    }
                }
            }
            if ($row['status'] > NOT_DOWNLOADED && $row['has_text'] == UNKNOWN && !(file_exists($text_path) || file_exists($page_path)) && !file_exists($card_path)) {
                if ($fix) {
                    mark_law($law_id, NOT_DOWNLOADED);
                }
            }
            print("\rChecked " . $i . ' of ' . $result_count . ' (' . floor($i / $result_count * 100) . '%)');
            $i++;
        }

        print("\n" . 'Downloaded card      : ' . $downloaded_card);
        print("\n" . 'Downloaded text      : ' . $downloaded_text . ' (without text: ' . $without_text . ')');
        print("\n" . 'Downloaded relations : ' . $downloaded_relations);
        print("\n" . 'Not downloaded : ' . $not_downloaded);
        print("\n" . '-------------------------------------------------');
        print("\n" . 'Junk directories           : ' . $nd_orphaned_dirs);
        print("\n" . 'Missing files for downloads: ' . $d_no_files);
        print("\n" . 'Fake content for downloads : ' . $d_fake_content);
        print("\n" . 'Has text, but not marked   : ' . $d_unknown_text_true_content);
        print("\n" . 'No text, but not marked    : ' . $d_unknown_text_no_text);
        if ($fix) {
            print("\n" . 'ALL PROBLEMS FIXED');
        }
        print("\n");

        return true;
    }
}