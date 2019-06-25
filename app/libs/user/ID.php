<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 3:10 PM
 */

namespace App\Libs\user;

use Jxlwqq\IdValidator\IdValidator;

class ID
{
    public $IDnumber;

    public function __construct($id)
    {
        $this->IDnumber = $id;
    }

    public function age(): int
    {
        $date = strtotime(substr($this->IDnumber, 6, 8));
        $today = strtotime('today');
        return reset($this->dateDiffAge($date, $today));
    }

    public function birthday(): string
    {
        $year = substr($this->IDnumber, 6, 4);
        $month = substr($this->IDnumber, 10, 2);
        $day = substr($this->IDnumber, 12, 2);

        return sprintf('%s-%s-%s', $year, $month, $day);
    }

    public function gender(): string
    {
        return ((intval(substr($this->IDnumber, 16, 1)) % 2) === 0) ? '女' : '男';
    }

    /**
     * 时间戳对比得到年龄
     * @param $before
     * @param $after
     * @return array
     */
    function dateDiffAge($before, $after): array
    {
        if ($before > $after) {
            $b = getdate($after);
            $a = getdate($before);
        } else {
            $b = getdate($before);
            $a = getdate($after);
        }
        $n = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];
        $y = $m = $d = 0;
        if ($a['mday'] >= $b['mday']) { //天相减为正
            if ($a['mon'] >= $b['mon']) {//月相减为正
                $y = $a['year'] - $b['year'];
                $m = $a['mon'] - $b['mon'];
            } else { //月相减为负，借年
                $y = $a['year'] - $b['year'] - 1;
                $m = $a['mon'] - $b['mon'] + 12;
            }
            $d = $a['mday'] - $b['mday'];
        } else {  //天相减为负，借月
            if ($a['mon'] == 1) { //1月，借年
                $y = $a['year'] - $b['year'] - 1;
                $m = $a['mon'] - $b['mon'] + 12;
                $d = $a['mday'] - $b['mday'] + $n[12];
            } else {
                if ($a['mon'] == 3) { //3月，判断闰年取得2月天数
                    $d = $a['mday'] - $b['mday'] + ($a['year'] % 4 == 0 ? 29 : 28);
                } else {
                    $d = $a['mday'] - $b['mday'] + $n[$a['mon'] - 1];
                }
                if ($a['mon'] >= $b['mon'] + 1) { //借月后，月相减为正
                    $y = $a['year'] - $b['year'];
                    $m = $a['mon'] - $b['mon'] - 1;
                } else { //借月后，月相减为负，借年
                    $y = $a['year'] - $b['year'] - 1;
                    $m = $a['mon'] - $b['mon'] + 12 - 1;
                }
            }
        }
        return ['age' => $y == 0 ? '' : $y, 'month' => $m == 0 ? '' : $m, 'day' => $d == 0 ? '' : $d];
    }

    public function validate()
    {
        $idValidator = new IdValidator();
        return $idValidator->isValid($this->IDnumber);
    }
}