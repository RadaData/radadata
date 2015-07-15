<?php

use ShvetsGroup\Tests\BaseTest;
use ShvetsGroup\Model\Laws\Law;
use Illuminate\Database\Capsule\Manager as DB;

class DownloadCommandTest extends BaseTest
{

    /**
     * @var ShvetsGroup\Command\DownloadCommand
     */
    protected $obj = null;

    protected function setUp()
    {
        parent::setUp();
        $this->obj = $this->container->get('download_command');

        DB::table('law_issuers')->truncate();
        DB::table('law_types')->truncate();
        DB::table('law_states')->truncate();
        DB::table('law_revisions')->truncate();
        DB::table('jobs')->truncate();
        DB::table('laws')->truncate();
    }

    protected function tearDown()
    {
        unset($this->obj);
    }

    public function testDownloadCard()
    {
        Law::firstOrCreate(['id' => '254к/96-вр']);
        $law = $this->obj->downloadCard('254к/96-вр');

        $this->assertEquals(file_get_contents(BASE_PATH . 'tests/fixtures/partials/254к/96-вр.txt'), $law->card);
        $this->assertEquals('Чинний', $law->getState());
        $this->assertArraysEqual(['Верховна Рада України'], $law->getIssuers());
        $this->assertArraysEqual(['Конституція', 'Закон'], $law->getTypes());
        $this->assertEquals(70, $law->revisions()->count());
        $this->assertEquals($law->getActiveRevision()->toArray(), [
            'date' => '2014-05-15',
            'law_id' => '254к/96-вр',
            'text' => '',
            'text_updated' => null,
            'comment' => '<u>Тлумачення</u>, підстава - <a href="/laws/show/v005p710-14" target="_blank">v005p710-14</a>',
            'status' => \ShvetsGroup\Model\Laws\Revision::NEEDS_UPDATE,
        ]);

        Law::firstOrCreate(['id' => '2952-17']);
        $law = $this->obj->downloadCard('2952-17');

        $this->assertEquals(2, $law->revisions()->count());
        $this->assertEquals($law->getActiveRevision()->toArray(), [
            'date' => '2011-02-01',
            'law_id' => '2952-17',
            'text' => '',
            'text_updated' => null,
            'comment' => '<u>Прийняття</u>',
            'status' => \ShvetsGroup\Model\Laws\Revision::NEEDS_UPDATE,
        ]);
        $this->assertEquals($this->redownloadCardJobsCount(), 1);

        DB::table('jobs')->truncate();

        $law = $this->obj->downloadCard('254к/96-вр');
        $this->assertEquals($this->redownloadCardJobsCount(), 0);
        $law = $this->obj->downloadCard('2952-17');
        $this->assertEquals($this->redownloadCardJobsCount(), 0);
    }

    private function redownloadCardJobsCount() {
        return DB::table('jobs')->where('method', 'downloadCard')->where('parameters', json_encode(['id' => '254к/96-вр', 're_download' => true]))->count();
    }
}