<?php

namespace App\Models;

use Phalcon\Db\RawValue;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Numericality;
use Phalcon\Validation\Validator\Between;
use Phalcon\Validation\Validator\Digit;

class Evaluate extends Model
{
    //状态 0=>未回复 1=>已回复
    const STATUS_UNREPLY = 0;
    const STATUS_REPLYED = 1;

    //0=>未被删除 1=>已被删除
    const IsDeleted_No = 0;
    const IsDeleted_Yes = 1;

    public $Id;

    public $TransferId;

    public $Service;

    public $Environment;

    public $Doctor;

    public $DoctorDiscuss;

    public $Status;

    public $UserId;

    public $CreateTime;

    public $Answer;

    public $AnswerTime;

    public $OrganizationId;

    public $ObserverId;

    public $IsDeleted;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('TransferId', Transfer::class, 'Id', ['alias' => 'Transfer']);
    }

    public function getSource()
    {
        return 'Evaluate';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('TransferId', [
            new PresenceOf(['message' => '转诊单ID不能为空']),
            new Numericality(['message' => '请输入数字']),
        ]);
        $validator->rules('Service', [
            new PresenceOf(['message' => '请为服务进行评价']),
            new Numericality(['message' => '请输入数字']),
            new Between(
                [
                    "minimum" => 0,
                    "maximum" => 5,
                    "message" => "分数必须在0到5之间",
                ]
            ),
        ]);
        $validator->rules('Environment', [
            new PresenceOf(['message' => '请为环境评价进行']),
            new Numericality(['message' => '请输入数字']),
            new Between(
                [
                    "minimum" => 0,
                    "maximum" => 5,
                    "message" => "分数必须在0到5之间",
                ]
            ),
        ]);
        $validator->rules('Doctor', [
            new PresenceOf(['message' => '请为医生进行评价']),
            new Numericality(['message' => '请输入数字']),
            new Between(
                [
                    "minimum" => 0,
                    "maximum" => 5,
                    "message" => "分数必须在0到5之间",
                ]
            ),
        ]);
        $validator->rule(['TransferId', 'UserId', 'OrganizationId', 'ObserverId'],
            new Digit([
                'message' => [
                    'TransferId'     => 'TransferId必须为整形数字',
                    'UserId'         => 'UserId必须为整形数字',
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                    'ObserverId'     => 'ObserverId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }

    public function afterCreate()
    {
        //医生总评论加一
        $user = User::findFirst(sprintf('Id=%d', $this->Transfer->AcceptDoctorId));
        $user->EvaluateAmount = new RawValue(sprintf('EvaluateAmount+%d', 1));
        $user->save();
        $user->refresh();
    }
}
