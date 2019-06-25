<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/7
 * Time: 9:39 AM
 */

namespace App\Libs\illness\models;


class Treatment
{
    /**
     * @var  int
     * @name 症候id
     */
    public $Id;
    /**
     * @var  string
     * @name 症候名称
     */
    public $Name;
    /**
     * @var  TreatmentProject[]
     * @name 治疗方案
     */
    public $TreatmentProject;
}