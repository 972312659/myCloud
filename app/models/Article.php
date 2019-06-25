<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\StringLength;

class Article extends Model
{
    //公告接收方
    const ACCEPT_B = 1;     //大B
    const ACCEPT_b = 2;     //小b
    const ACCEPT_BOTH = 3;  //都接收

    //发布状态
    const STATUS_UN = 0;   //未发布
    const STATUS_ED = 1;   //已发布

    //发布方式
    const STYLE_NOW = 1;    //即时
    const STYLE_TIMING = 2; //定时

    public $Id;

    public $Title;

    public $Author;

    public $Content;

    public $CategoryId;

    public $OrganizationId;

    public $Style;

    public $ReleaseTime;

    public $Status;

    public $CreateTime;

    public $AcceptOrganization;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_CREATE = '';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('CategoryId', Category::class, 'Id', ['alias' => 'Category']);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
    }

    public function getSource()
    {
        return 'Article';
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'Author'         => [new PresenceOf(['message' => '作者不能为空']), new StringLength(["min" => 0, "max" => 30, "messageMaximum" => '作者最长不超过30个字符']),],
            'Title'          => [new PresenceOf(['message' => '标题不能为空']), new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '标题最长不超过100个字符'])],
            'CategoryId'     => [new PresenceOf(['message' => '分类不能为空']), new Digit(['message' => 'CategoryId必须为整形数字'])],
            'OrganizationId' => [new PresenceOf(['message' => '机构不能为空']), new Digit(['message' => 'SendHospitalId必须为整形数字'])],
            'Style'          => [new PresenceOf(['message' => '发布方式不能为空'])],
        ];
        $scenes = [
            self::SCENE_CREATE => ['OrganizationId', 'Title', 'CategoryId', 'Author', 'Style'],
        ];
        foreach ($scenes[$scene] as $v) {
            $this->selfValidate->rules($v, $fields[$v]);
        }
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Author', [
            new PresenceOf(['message' => '作者不能为空']),
            new StringLength(["min" => 0, "max" => 30, "messageMaximum" => '作者最长不超过30个字符']),
        ]);
        $validator->rules('Title', [
            new PresenceOf(['message' => '标题不能为空']),
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '标题最长不超过100个字符']),
        ]);
        $validator->rule('CategoryId',
            new PresenceOf(['message' => '分类不能为空'])
        );
        $validator->rule('OrganizationId',
            new PresenceOf(['message' => '机构不能为空'])
        );
        $validator->rule('Style',
            new PresenceOf(['message' => '发布方式不能为空'])
        );
        $validator->add(['CategoryId', 'OrganizationId'],
            new Digit([
                'message' => [
                    'CategoryId'     => 'CategoryId必须为整形数字',
                    'OrganizationId' => 'SendHospitalId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }

    public function afterCreate()
    {
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null) {
            $this->staffNoticeLog(StaffNoticeLog::CREATE);
        }
    }

    public function afterUpdate()
    {
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null) {
            $this->staffNoticeLog(StaffNoticeLog::UPDATE);
        }
    }

    public function beforeDelete()
    {
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null) {
            $this->staffNoticeLog(StaffNoticeLog::DELETE);
        }
    }

    private function staffNoticeLog($status)
    {
        $staffHospitalLog = new StaffNoticeLog();
        $staffHospitalLog->StaffId = $this->getDI()->getShared('session')->get('auth')['Id'];
        $staffHospitalLog->ArticleId = $this->Id;
        $staffHospitalLog->Created = time();
        $staffHospitalLog->Operated = $status;
        $staffHospitalLog->save();
    }
}
