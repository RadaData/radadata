<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Model\Law;
use Symfony\Component\Console as Console;

class CheckCommand extends Console\Command\Command
{

    /**
     * @var \ShvetsGroup\Service\JobsManager
     */
    private $jobsManager;

    /**
     * @param string   $downloadsDir
     * @param \ShvetsGroup\Service\JobsManager $jobsManager
     */
    public function __construct($downloadsDir, $jobsManager)
    {
        parent::__construct('check');

        $this->setDescription('Check the downloaded files for issues.');
        $this->addOption('fix', 'f', Console\Input\InputOption::VALUE_NONE, 'Try to fix all problems.');

        $this->downloadsDir = BASE_PATH . $downloadsDir;
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
        $fix = $input->getOption('fix');

        $downloaded_card = Law::where('status', Law::DOWNLOADED_CARD)->count();
        $downloaded_text = Law::where('status', Law::DOWNLOADED_REVISIONS)->count();
        $downloaded_relations = Law::where('status', Law::DOWNLOADED_RELATIONS)->count();
        $without_text = Law::where('status', '>', Law::NOT_DOWNLOADED)->where('has_text', Law::NO_TEXT)->count();
        $not_downloaded = Law::where('status', Law::NOT_DOWNLOADED)->count();

        $law_dir = $this->downloadsDir . '/zakon.rada.gov.ua/laws/show/';

        function is_fake($html, $is_text = true)
        {
            return downloader()->detectFakeContent($html, [
                'required' => ['<div id="article"', '</body>'],
                'stop'     => $is_text ? ['<div id="pan_title"'] : null
            ]);
        }

        function is_403($html)
        {
            return downloader()->detectFakeContent($html, [
                'stop' => ['Error 403', 'Доступ заборонено', 'Ваш IP автоматично заблоковано']
            ]);
        }

        function remove_dir($dir)
        {
            exec('rm -rf ' . $dir);
        }

        $result_count = Law::where('status', '<', Law::SAVED)->count();
        $nd_orphaned_dirs = 0;
        $d_broken_card = 0;
        $d_no_files = 0;
        $d_fake_content = 0;
        $d_unknown_text_true_content = 0;
        $d_unknown_text_no_text = 0;
        $i = 1;
        Law::where('status', '<', Law::SAVED)->orderBy('id')->chunk(200, function($laws) use ($fix, $law_dir, &$i, &$result_count, &$nd_orphaned_dirs, &$d_broken_card, &$d_no_files, &$d_fake_content, &$d_unknown_text_true_content, &$d_unknown_text_no_text) {
            foreach ($laws as $law) {
                $law_path = $law_dir . $law->id;
                $card_path = $law_dir . $law->id . '/card.html';
                $text_path = $law_dir . $law->id . '/text.html';
                $page_path = $law_dir . $law->id . '/page.html';

                if ($law->status == Law::NOT_DOWNLOADED && is_dir($law_path)) {
                    $nd_orphaned_dirs++;
                    if ($fix) {
                        remove_dir($law_path);
                    }
                    continue;
                }

                if ($law->status > Law::NOT_DOWNLOADED && file_exists($card_path) && is_403(file_get_contents($card_path))) {
                    $d_broken_card++;
                    if ($fix) {
                        remove_dir($law_path);
                        $law->update(['status' => Law::NOT_DOWNLOADED]);
                    }
                }

                if ($law->status >= Law::DOWNLOADED_REVISIONS && $law->has_text == Law::HAS_TEXT && !file_exists($text_path) && !file_exists($page_path)) {
                    $d_no_files++;
                    if ($fix) {
                        remove_dir($law_path);
                        $law->update(['status' => Law::NOT_DOWNLOADED]);
                    }
                }
                if ($law->status >= Law::DOWNLOADED_REVISIONS && $law->has_text == Law::HAS_TEXT && (file_exists($text_path) || file_exists($page_path))) {
                    if ((file_exists($text_path) && is_fake(file_get_contents($text_path), 1)) || (file_exists($page_path) && is_fake(file_get_contents($page_path), 0))) {
                        $d_fake_content++;
                        if ($fix) {
                            remove_dir($law_path);
                            $law->update(['status' => Law::NOT_DOWNLOADED]);
                        }
                    }
                }

                if ($law->status > Law::NOT_DOWNLOADED && $law->has_text == Law::UNKNOWN && (file_exists($text_path) || file_exists($page_path))) {
                    if ((file_exists($text_path) && is_fake(file_get_contents($text_path), 1)) || (file_exists($page_path) && is_fake(file_get_contents($page_path), 0))) {
                        $d_fake_content++;
                        if ($fix) {
                            remove_dir($law_path);
                            $law->update(['status' => Law::NOT_DOWNLOADED]);
                        }
                    }
                }

                if ($law->status >= Law::DOWNLOADED_REVISIONS && $law->has_text == Law::UNKNOWN && !(file_exists($text_path) || file_exists($page_path)) && file_exists($card_path)) {
                    $html = file_get_contents($card_path);
                    if (strpos($html, 'Текст відсутній') !== false) {
                        $d_unknown_text_no_text++;
                        if ($fix) {
                            $law->update(['status' => Law::DOWNLOADED_CARD, 'has_text' => Law::NO_TEXT]);
                        }
                    } else {
                        $d_no_files++;
                        if ($fix) {
                            $law->update(['status' => Law::NOT_DOWNLOADED]);
                        }
                    }
                }
                if ($law->status > Law::NOT_DOWNLOADED && $law->has_text == Law::UNKNOWN && !(file_exists($text_path) || file_exists($page_path)) && !file_exists($card_path)) {
                    if ($fix) {
                        $law->update(['status' => Law::NOT_DOWNLOADED]);
                    }
                }
                print("\rChecked " . $i . ' of ' . $result_count . ' (' . floor($i / $result_count * 100) . '%)');
                $i++;
            }
        });

        print("\n" . 'Downloaded card      : ' . $downloaded_card);
        print("\n" . 'Downloaded text      : ' . $downloaded_text . ' (without text: ' . $without_text . ')');
        print("\n" . 'Downloaded relations : ' . $downloaded_relations);
        print("\n" . 'Not downloaded : ' . $not_downloaded);
        print("\n" . '-------------------------------------------------');
        print("\n" . 'Junk directories           : ' . $nd_orphaned_dirs);
        print("\n" . 'Broken card page           : ' . $d_broken_card);
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