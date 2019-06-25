<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 6:20 PM
 * 病例
 */

namespace App\Libs\illness\models;

class CaseBook
{
    /**
     * @var Symptom[]
     * @name 症状
     */
    public $Symptom;
    /**
     * @var  SymptomAdd[]
     * @name 症状补充
     */
    public $SymptomAdd;
    /**
     * @var  Treatment
     * @name 治疗方案
     */
    public $Treatment;

    public static function getProperties()
    {
        $result = [];
        $reflectionClass = new \ReflectionClass(new self());
        $properties = $reflectionClass->getProperties();
        foreach ($properties as $property) {
            $result[] = $property->name;
        }
        return $result;
    }
}