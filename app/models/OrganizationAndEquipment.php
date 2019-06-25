<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class OrganizationAndEquipment extends Model
{
    //是否共享 1=>不共享 2=>共享 3=>等待审核 4=>审核失败
    const SHARE_CLOSED = 1;
    const SHARE_SHARE = 2;
    const SHARE_WAIT = 3;
    const SHARE_FAILED = 4;

    //是否展示
    const DISPLAY_ON = 1;
    const DISPLAY_OFF = 2;

    public $OrganizationId;

    public $EquipmentId;

    public $Number;

    public $Amount;

    public $Intro;

    public $CreateTime;

    public $UpdateTime;

    public $Share;

    public $Display;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('SectionId', Section::class, 'Id', ['alias' => 'Section']);
        $this->belongsTo('EquipmentId', Equipment::class, 'Id', ['alias' => 'Equipment']);
    }

    public function getSource()
    {
        return 'OrganizationAndEquipment';
    }

    public function validation()
    {
        $validator = new Validation();

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
