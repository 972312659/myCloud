<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;

class ServicePackage extends Model
{
    public $Id;

    public $Name;

    public $Price;

    public $ShareToHospital;

    public $ShareToSlave;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ServicePackage';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('Name',
            new PresenceOf(['message' => '名称不能为空'])
        );
        $validator->add(['Price'],
            new Digit([
                'message' => [
                    'Price' => '价格必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }
}
