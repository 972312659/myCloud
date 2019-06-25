<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/8
 * Time: 下午2:51
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\StringLength;

class OrganizationRelationship extends Model
{
    //开网点的方式 1=>pc 2=>手机web
    const WAY_PC = 1;
    const WAY_MOBILE_WEB = 2;

    public $MainId;

    public $MinorId;

    public $MainName;

    public $MinorName;

    public $SalesmanId;

    public $RuleId;

    public $MinorType;

    public $Created;

    public $Way;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('MainId', Organization::class, 'Id', ['alias' => 'Main']);
        $this->belongsTo('MinorId', Organization::class, 'Id', ['alias' => 'Minor']);
    }

    public function getSource()
    {
        return 'OrganizationRelationship';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules(['MinorName'], [
            new PresenceOf(['message' => '网点名字不能为空']),
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '网点名字最长不超过100个字符']),
        ]);
        $validator->rules('RuleId', [
            new PresenceOf(['message' => '请选择分组']),
        ]);
        $validator->rules(['MainId', 'MinorId'], [
            new Uniqueness(['message' => '该下游已存在']),
        ]);
        $validator->rule(['MainId', 'MinorId', 'RuleId'],
            new Digit([
                'message' => [
                    'MainId'  => 'MainId必须为整形数字',
                    'MinorId' => 'MinorId必须为整形数字',
                    'RuleId'  => 'RuleId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }
}