<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/12
 * Time: 2:25 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class Syndrome extends Model
{
    //0=>西医症候群 1=>中医症候群
    const IsChineseMedicine_No = 0;
    const IsChineseMedicine_Yes = 1;

    public $Id;

    public $Name;

    public $Image;

    public $Intro;

    public $IllnessId;

    public $MakeSureScore;

    public $IsChineseMedicine;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'Syndrome';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Name', [
            new PresenceOf(['message' => '症候名称不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '症候名称最长不超过50']),
        ]);
        return $this->validate($validator);
    }
}