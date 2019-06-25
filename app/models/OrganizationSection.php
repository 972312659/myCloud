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

class OrganizationSection extends Model
{
    //类型 1=>自有 2=>供应商 3=>其他医院共享
    const TYPE_SELF = 1;
    const TYPE_SUPPLIER = 2;
    const TYPE_SHARE = 3;
    const TYPE_NAME = [1 => '自有', 2 => '专供', 3 => '共享'];

    public $OrganizationId;

    public $HospitalId;

    public $SectionId;

    public $Type;

    public $Sort;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'OrganizationSection';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->add('Sort',
            new Between(['minimum' => 0, 'maximum' => 1000, 'message' => '最大不超过1000'])
        );
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $self = OrganizationAndSection::findFirst([
            'conditions' => 'OrganizationId=?0 and SectionId=?1',
            'bind'       => [$this->OrganizationId, $this->SectionId],
        ]);
        $this->Sort = 0;
        if ($self) {
            $this->Sort = $self->Rank;
        } else {
            $supplier = self::findFirst([
                'conditions' => 'HospitalId=?0 and SectionId=?1',
                'bind'       => [$this->HospitalId, $this->SectionId],
            ]);
            if ($supplier) {
                $this->Sort = $supplier->Sort;
            }
        }
    }
}