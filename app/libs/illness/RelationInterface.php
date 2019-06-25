<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/7
 * Time: 2:18 PM
 */

namespace App\Libs\illness;


interface RelationInterface
{
    public function exist(): bool;

    public function create();
}