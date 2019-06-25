<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/7
 * Time: 11:51 AM
 */

namespace App\Libs\illness;


interface FileCreateInterface
{
    /**
     * 创建一个疾病档案
     * @return string
     */
    public function create(): string;
}