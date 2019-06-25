<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/8/3
 * Time: 下午4:49
 */

namespace App\Libs;

class Probability
{
    public static function get($arr)
    {
        $pro_sum = array_sum($arr);
        $rand_num = mt_rand(1, $pro_sum);
        $tmp_num = 0;
        $n = 0;
        foreach ($arr as $k => $val) {
            if ($rand_num <= $val + $tmp_num) {
                $n = $k;
                break;
            } else {
                $tmp_num += $val;
            }
        }
        return $n;
    }
}
