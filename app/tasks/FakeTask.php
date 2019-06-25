<?php

use Phalcon\Cli\Task;
use App\Libs\fake\composer\Composer;
use App\Libs\fake\composer\Manager;
use App\Libs\fake\transfer\Generator;
use App\Libs\fake\transfer\Seed;
use App\Libs\fake\Faker;
use App\Libs\task\OptionalParams;

class FakeTask extends Task
{
    use OptionalParams;

    public function transferAction(array $params = [])
    {
        $this->setParam('date', date('Y-m-d'));
        // $this->setParam('num', 1);
        $this->setParam('have', '38:100');
        $this->parseParams($params);

        $composer = new Composer(new Manager());

        $generator = new Generator(new Seed());
        $faker = new Faker($composer, $generator, new \App\Libs\fake\transfer\disease\Disease());

        $date = $this->getParam('date');
        // $num = $this->getParam('num');
        $have = $this->getParam('have');

        $split = explode(',', $date);
        $start = new DateTime($split[0]);
        $end = new DateTime(empty($split[1]) ? $split[0] : $split[1]);
        $end->setTime(23, 59, 59);
        $interval = new \DateInterval('P1D');
        $range = new \DatePeriod($start, $interval, $end);

        $probability = [];
        $have = explode(',', $have);
        foreach ($have as $item) {
            $temp = explode(':', $item);
            $probability[$temp[0]] = $temp[1];
        }
        foreach ($range as $value) {
            $n = \App\Libs\Probability::get($probability);
            for ($i = 0; $i < $n; $i++) {
                try {
                    $this->db->begin();
                    $faker->createTransfer($value);
                    $this->db->commit();
                } catch (\Exception $e) {
                    $this->db->rollback();
                    echo $e->getMessage() . PHP_EOL;
                }
            }
        }
    }
}
