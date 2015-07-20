<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Model\Laws\Law;
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
        $this->addOption('old_files', 'o', Console\Input\InputOption::VALUE_NONE, 'Move old downloads to new locations.');

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
        $old = $input->getOption('old_files');

        if ($old) {
            return $this->move_files();
        }

        $downloaded_card = Law::where('status', Law::DOWNLOADED_CARD)->count();
        $downloaded_text = Law::where('status', Law::DOWNLOADED_REVISIONS)->count();
        $downloaded_relations = Law::where('status', Law::DOWNLOADED_RELATIONS)->count();
        $without_text = Law::where('status', '>', Law::NOT_DOWNLOADED)->where('has_text', Law::NO_TEXT)->count();
        $not_downloaded = Law::where('status', Law::NOT_DOWNLOADED)->count();

        $law_dir = $this->downloadsDir . '/zakon.rada.gov.ua/laws/show/';

        function is_fake($html, $is_text = true)
        {
            return downloader()->detectFakeContent($html);
        }

        function is_403($html)
        {
            return downloader()->detectFakeContent($html, '403');
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

                if ($law->status > Law::NOT_DOWNLOADED && ((file_exists($card_path) && is_403(file_get_contents($card_path))) || (!file_exists($card_path)))) {
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

    function move_files()
    {
        function rrmdir($dir) {
            if (is_dir($dir)) {
                $objects = scandir($dir);
                foreach ($objects as $object) {
                    if ($object != "." && $object != "..") {
                        if (filetype($dir."/".$object) == "dir") rrmdir($dir."/".$object); else unlink($dir."/".$object);
                    }
                }
                reset($objects);
                rmdir($dir);
            }
        }

        function is_dir_empty($dir) {
            if (!is_readable($dir)) return NULL;
            return (count(scandir($dir)) == 2);
        }

        $base_laws = DOWNLOADS_PATH . 'zakon.rada.gov.ua/laws/';
        $dirs = array_merge(glob($base_laws . 'show/*/*', GLOB_ONLYDIR | GLOB_MARK), glob($base_laws . 'show/*', GLOB_ONLYDIR | GLOB_MARK));

        foreach ($dirs as $dir) {
            if (glob($dir . '*', GLOB_ONLYDIR)) {
                continue;
            }
            if (glob($dir . 'card.html')) {
                continue;
            }
            rrmdir($dir);
        }


        $base_laws = DOWNLOADS_PATH . 'zakon.rada.gov.ua/laws/';
        $files = array_merge(glob($base_laws . 'show/*/*/card.html'), glob($base_laws . 'show/*/card.html'));

        foreach ($files as $file) {
            $law_id = preg_replace('|' . $base_laws . 'show/(.*?)/card.html|', '$1', $file);
            $new_name_card = $base_laws . 'card/' . $law_id . '.html';

            preg_match('|<span style="color: #.*?">(.*?)</span>(?:</b></a>\n<img src="http://zakonst.rada.gov.ua/images/docs.gif" title="Документ"> <span class="num" style="color:#999999">поточна редакція, .*?, <a href=".*">перейти »</a></span>)?</dt>\n<dd><a name="Current">|', file_get_contents($file), $matches);
            $revision = isset($matches[1]) ? $matches[1] : null;
            if ($revision) {
                if (!preg_match('|[0-9]{2}\.[0-9]{2}\.[0-9]{4}|', $revision) || !date_create_from_format('d.m.Y', $revision)) {
                    throw new \Exception("Revision has not been found in #{$file}.");
                }
                $date = date_format(date_create_from_format('d.m.Y', $revision), 'Ymd');
                $new_name_text = $base_laws . 'show/' . $law_id . '/ed' . $date . '/page.html';

                $old_files = [];
                $text = '';
                if (file_exists($base_laws . 'show/' . $law_id . '/text.html')) {
                    $text_file_name = $base_laws . 'show/' . $law_id . '/text.html';
                    $old_files[] = $text_file_name;

                    $text = crawler(file_get_contents($text_file_name))->filter('.txt')->html();
                }
                elseif (file_exists($base_laws . 'show/' . $law_id . '/page.html')) {
                    $text_file_name = $base_laws . 'show/' . $law_id . '/page.html';
                    $old_files[] = $text_file_name;

                    $page = crawler(file_get_contents($base_laws . 'show/' . $law_id . '/page.html'));
                    $text = $page->filter('.txt')->html();
                    $pager = $page->filterXPath('(//span[@class="nums"])[1]/br/preceding-sibling::a[1]');
                    $page_count = $pager->count() ? $pager->text() : 1;

                    for ($i = 2; $i <= $page_count; $i++) {
                        $text_file_name = $base_laws . 'show/' . $law_id . '/page' . $i . '.html';

                        if (!file_exists($text_file_name)) {
                            $text = '';
                            foreach ($old_files as $file_name) {
                                unlink($file_name);
                            }
                            break;
                        }

                        $old_files[] = $text_file_name;
                        $page = crawler(file_get_contents($text_file_name));
                        $text .= $page->filter('.txt')->html();
                    }
                }

                if (file_exists($new_name_text)) {
                    foreach ($old_files as $file_name) {
                        unlink($file_name);
                    }
                }
                elseif ($text) {
                    $new_dir = dirname($new_name_text);
                    if (!is_dir($new_dir)) {
                        mkdir($new_dir, 0777, true);
                    }

                    file_put_contents($new_name_text, '<html><body><div class="txt txt-old">' . $text . '</div></body></html>');
                    touch($new_name_text, filemtime($old_files[0]));

                    foreach ($old_files as $file_name) {
                        unlink($file_name);
                    }
                }
            }

            if (file_exists($new_name_card)) {
                unlink($file);
            }
            else {
                $new_dir = dirname($new_name_card);
                if (!is_dir($new_dir)) {
                    mkdir($new_dir, 0777, true);
                }
                rename($file, $new_name_card);
            }

            if (is_dir_empty($base_laws . 'show/' . $law_id)) {
                rrmdir($base_laws . 'show/' . $law_id);

                $parent = dirname($base_laws . 'show/' . $law_id);
                if (is_dir_empty($parent)) {
                    rrmdir($parent);
                }
            }
        }
    }
}