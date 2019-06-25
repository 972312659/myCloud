<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/12
 * Time: 5:11 PM
 */

namespace App\Enums;


class SymptomDegree
{
    public static $map = [
        //风湿病
        'Rheumatism' => [
            ['Name' => '轻度', 'Score' => 1],
            ['Name' => '较重', 'Score' => 2],
            ['Name' => '严重', 'Score' => 3],
        ],
    ];

    public static function value(string $illnessName, int $score)
    {
        foreach (self::$map[$illnessName] as $item) {
            if ($item['Score'] == $score) {
                return $item['Name'];
            }
        }
        return null;
    }
}