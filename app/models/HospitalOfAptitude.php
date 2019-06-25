<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;

class HospitalOfAptitude extends Model
{
    public $OrganizationId;

    public $BusinessLicense;

    public $Level;

    public $Front;

    public $Reverse;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'HospitalOfAptitude';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules(['BusinessLicense', 'Level', 'Front', 'Reverse'], [
            new PresenceOf(['message' => '请填写名称']),
        ]);
        $validator->rules('OrganizationId', [
            new Uniqueness(['message' => '资质已上传，请直接去更改']),
        ]);
        $validator->rule(['OrganizationId'],
            new Digit([
                'message' => [
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }
}
