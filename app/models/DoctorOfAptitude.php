<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class DoctorOfAptitude extends Model
{
    public $OrganizationId;

    public $DoctorId;

    public $Certificate;

    public $Front;

    public $Reverse;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'DoctorOfAptitude';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('OrganizationId',
            new PresenceOf(['message' => '医院不能为空'])
        );
        $validator->rule('DoctorId',
            new PresenceOf(['message' => '医生不能为空'])
        );
        $validator->add(['DoctorId', 'OrganizationId',],
            new Digit([
                'message' => [
                    'DoctorId'       => 'DoctorId必须为整形数字',
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }
}
