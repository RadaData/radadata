<?php

namespace ShvetsGroup\Command;

use ShvetsGroup\Model\Law;
use Symfony\Component\Console as Console;

class DumpCommand extends Console\Command\Command
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
        parent::__construct('dump');

        $this->setDescription('Move all downloaded files to database.');

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
        $result_count = Law::where('status', '<', Law::SAVED)->count();
        // TODO: CHUNKS
        $result = Law::where('status', '<', Law::SAVED)->orderBy('law_id')->get();

        $law_dir = $this->downloadsDir . '/zakon.rada.gov.ua/laws/show/';

        $i = 1;
        foreach ($result as $law) {
            $law_id = $law->law_id;
            $law_path = $law_dir . $law_id;

            foreach (glob($law_path . "/*") as $file_path) {
                $file = basename($file_path, ".html");
                $download_date = date('Y-m-d H:i:s', filemtime($file_path));
                $text = file_get_contents($file_path);

                db('db')->prepare("INSERT IGNORE INTO laws_raw (law_id, file, download_date, text) VALUES (:law_id, :file, :download_date, :text)")
                    ->execute([
                        ':law_id' => $law_id,
                        ':file' => $file,
                        ':download_date' => $download_date,
                        ':text' => $text
                    ]);
                Law::find($law_id)->update(['status' => Law::SAVED]);
            }

            print("\rAdded " . $i . ' of ' . $result_count . ' (' . floor($i / $result_count * 100) . '%)');
            $i++;
        }

        return true;
    }
}