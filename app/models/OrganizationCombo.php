<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/5
 * Time: 下午3:17
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Between;

class OrganizationCombo extends Model
{
    //类型 1=>自有 2=>供应商 3=>其他医院共享
    const TYPE_SELF = 1;
    const TYPE_SUPPLIER = 2;
    const TYPE_SHARE = 3;

    public $OrganizationId;

    public $HospitalId;

    public $ComboId;

    public $Type;

    public $Sort;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'OrganizationCombo';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->add('Sort',
            new Between(['minimum' => 0, 'maximum' => 1000, 'message' => '最大不超过1000'])
        );
        return $this->validate($validate);
    }
}