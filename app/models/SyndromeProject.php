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

class SyndromeProject extends Model
{
    public $Id;

    public $Name;

    public $IllnessId;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'SyndromeProject';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Name', [
            new PresenceOf(['message' => '方案名不能为空']),
            new StringLength(["min" => 0, "max" => 200, "messageMaximum" => '方案名最长不超过200']),
        ]);
        return $this->validate($validator);
    }
}