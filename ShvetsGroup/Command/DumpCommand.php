<?php

namespace ShvetsGroup\Command;

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
        $result_count = db('db')->query('SELECT COUNT(*) FROM laws WHERE status = 1')->fetchColumn();
        $result = db('db')->query('SELECT law_id FROM laws  WHERE status = 1 ORDER BY law_id');
        $law_dir = $this->downloadsDir . '/zakon.rada.gov.ua/laws/show/';

        $i = 1;
        foreach ($result as $row) {
            $law_id = $row['law_id'];
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
                mark_law($law_id, SAVED);
            }

            print("\rAdded " . $i . ' of ' . $result_count . ' (' . floor($i / $result_count * 100) . '%)');
            $i++;
        }

        return true;
    }
}