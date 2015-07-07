<?php

namespace ShvetsGroup\Command;

use Illuminate\Database\Capsule\Manager as DB;
use ShvetsGroup\Model\Law;
use Symfony\Component\Console as Console;

class DumpCommand extends Console\Command\Command
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
        parent::__construct('dump');

        $this->setDescription('Move all downloaded files to database.');

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
        $result_count = Law::where('status', '<', Law::SAVED)->count();
        // TODO: CHUNKS
        $result = Law::where('status', '<', Law::SAVED)->orderBy('id')->get();

        $law_dir = $this->downloadsDir . '/zakon.rada.gov.ua/laws/show/';

        $i = 1;
        foreach ($result as $law) {
            $law_path = $law_dir . $law->id;

            foreach (glob($law_path . "/*") as $file_path) {
                $file = basename($file_path, ".html");
                $download_date = date('Y-m-d H:i:s', filemtime($file_path));
                $text = file_get_contents($file_path);

                DB::table('laws_raw')->insert(
                    ['law_id' => $law->id, 'file' => $file, 'download_date' => $download_date, 'text' => $text]
                );
                $law->update(['status' => Law::SAVED]);
            }

            print("\rAdded " . $i . ' of ' . $result_count . ' (' . floor($i / $result_count * 100) . '%)');
            $i++;
        }

        return true;
    }
}