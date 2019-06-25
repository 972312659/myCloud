<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 5:18 PM
 */

namespace App\Libs\illness\models;

class Symptom
{
    /**
     * @var  int
     */
    public $Id;
    /**
     * @var  string
     * @name 症状名称
     */
    public $Name;
    /**
     * @var  int
     * @name 症状程度
     */
    public $Level;
    /**
     * @var  string
     * @name 症状程度
     */
    public $LevelName;
}