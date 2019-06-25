<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class EquipmentOfAptitude extends Model
{
    public $OrganizationId;

    public $EquipmentId;

    public $Number;

    public $Manufacturer;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'EquipmentOfAptitude';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule(['OrganizationId', 'EquipmentId'],
            new PresenceOf([
                'message' => [
                    'OrganizationId' => '医院不能为空',
                    'EquipmentId'    => '设备不能为空',
                ],
            ])
        );
        $validator->rule(['OrganizationId', 'EquipmentId'],
            new Digit([
                'message' => [
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                    'EquipmentId'    => 'EquipmentId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }
}
