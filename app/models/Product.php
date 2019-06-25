<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Libs\Sphinx;
use App\Libs\sphinx\TableName as SphinxTableName;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class Product extends Model
{
    //审核状态  1=>待审核 2=>审核通过 3=>审核不通过 4=>审核通过已下架 5=>撤回待审核
    const AUDIT_WAIT = 1;
    const AUDIT_ON = 2;
    const AUDIT_REFUSE = 3;
    const AUDIT_OFF = 4;
    const AUDIT_RECALL = 5;
    const AUDIT_NAME = [1 => '待审核', 2 => '审核通过', 3 => '审核不通过', 4 => '审核通过已下架', 5 => '撤回'];

    //发布对象 1=>医院 2=>网点 4=>个人(二进制位运算后存入数据库)
    const WAY_HOSPITAL_ID = 1;
    const WAY_HOSPITAL_NAME = '医院';
    const WAY_SLAVE_ID = 2;
    const WAY_SLAVE_NAME = '网点';
    const WAY_PERSONAL_ID = 4;
    const WAY_PERSONAL_NAME = '个人';
    const WAY_SHOW = [['Id' => self::WAY_HOSPITAL_ID, 'Name' => self::WAY_HOSPITAL_NAME], ['Id' => self::WAY_SLAVE_ID, 'Name' => self::WAY_SLAVE_NAME]];

    public $Id;

    public $Name;

    public $Description;

    public $Status;

    public $Audit;

    public $Updated;

    public $Created;

    public $OrganizationId;

    public $Manufacturer;

    public $Way;

    public $ProductCategoryId;

    public function initialize()
    {
        $this->keepSnapshots(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->hasMany('Id', ProductProperty::class, 'ProductId', ['alias' => 'Properties']);
        $this->hasMany('Id', ProductAttribute::class, 'ProductId', ['alias' => 'Attributes']);
        $this->belongsTo('ProductCategoryId', ProductCategory::class, 'Id', ['alias' => 'ProductCategory']);
    }

    public function getSource()
    {
        return 'Product';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Name', [
            new PresenceOf(['message' => '商品名不能为空']),
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '商品名不能超过100个字符']),
        ]);
        $validate->rules('Manufacturer', [
            new PresenceOf(['message' => '生产厂商不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '生产厂商不能超过50个字符']),
        ]);
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Audit = self::AUDIT_WAIT;
        $this->Created = time();
        $this->Updated = time();
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (count($changed)) {
            if (in_array('Name', $changed)) {
                $productSphinx = new \App\Libs\sphinx\model\Product(new Sphinx($this->getDI()->getShared('sphinx'), SphinxTableName::Product));
                $productSphinx->update($this->Id, ['submitname' => $this->Name]);
            }
        }
    }
}