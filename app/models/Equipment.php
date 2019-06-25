<?php

namespace App\Models;

use App\Libs\Sphinx;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;

class Equipment extends Model
{
    //设备默认图片
    const DEFAULT_IMAGE = 'https://referral-store.100cbc.com/default_avatar.jpg';

    public $Id;

    public $Name;

    public $TypeSpecification;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'Equipment';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('Name',
            new PresenceOf(['message' => '设备名称不能为空'])
        );
        return $this->validate($validator);
    }

    public function afterCreate()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'equipment');
        $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
    }

    public function afterUpdate()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'equipment');
        $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
    }
}
