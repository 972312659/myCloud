<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/18
 * Time: 上午11:09
 */

namespace App\Libs;


class ArrayIsSame
{
    public static function isSame($arr1, $arr2)
    {
        sort($arr1);
        sort($arr2);
        return $arr1 == $arr2;
    }

}