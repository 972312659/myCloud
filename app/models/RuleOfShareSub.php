<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class RuleOfShareSub extends Model
{
    //分润方式
    const IS_FIXED_NO = 0;//按比例
    const IS_FIXED_YES = 1;//固定

    public $Id;

    public $RuleOfShareId;

    public $MinAmount;

    public $MaxAmount;

    public $IsFixed;

    public $Value;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'RuleOfShareSub';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('MinAmount', [
            new PresenceOf(['message' => '最小值不能为空']),
            new Digit(['message' => '最小值的格式错误']),
        ]);
        $validate->rules('Value', [
            new Digit(['message' => '值的格式错误']),
        ]);
        return $this->validate($validate);
    }
}
