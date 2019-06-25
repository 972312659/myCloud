<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;
use Phalcon\Validation\Validator\Between;
use Phalcon\Validation\Validator\Digit;

class OrganizationUser extends Model
{
    //消息开关
    const SWITCH_ON = 1;
    const SWITCH_OFF = 2;

    //使用开关
    const USESTATUS_ON = 1;
    const USESTATUS_OFF = 2;

    //是否展示
    const DISPLAY_ON = 1;
    const DISPLAY_OFF = 2;

    //标签
    const LABEL_EXPERT = 1;//专家
    const LABEL_ADMISSION = 2; //全科接诊
    const LABEL_ADMIN = 10;//平台创建管理员

    //执业资格证认证 0=>未认证 1=>已认证
    const IDENTIFIED_OFF = 0;
    const IDENTIFIED_ON = 1;

    //是否共享 1=>不共享 2=>共享 3=>等待审核 4=>审核失败 5=>共享关闭（只能由2到5）
    const SHARE_CLOSED = 1;
    const SHARE_SHARE = 2;
    const SHARE_WAIT = 3;
    const SHARE_FAILED = 4;
    const SHARE_PAUSE = 5;

    //是否是医生 0=>普通员工 1=>医生 2=>药师
    const IS_DOCTOR_NO = 0;
    const IS_DOCTOR_YES = 1;
    const IS_DOCTOR_Pharmacist = 2;

    //是否是全科问诊 0=>否 1=>是
    const GENERALINQUIRY_NO = 0;
    const GENERALINQUIRY_YES = 1;

    //是否是市场销售 0=>否 1=>是
    const Is_Salesman_No = 0;
    const Is_Salesman_Yes = 1;

    //默认头像
    const DEFAULT_IMAGE = 'https://referral-store.100cbc.com/default_avatar.jpg';
    const DEFAULT_SLAVE_IMAGE = 'https://referral-store.100cbc.com/default_avatar.jpg';

    //是否被删除 0:正常 1:已删除
    const IsDelete_No = 0;
    const IsDelete_Yes = 1;

    public $OrganizationId;

    public $UserId;

    public $CreateTime;

    public $LastLoginTime;

    public $LastLoginIp;

    public $Image;

    public $Role;

    public $SectionId;

    public $Title;

    public $Intro;

    public $Skill;

    public $Direction;

    public $Experience;

    public $IsDoctor;

    public $Display;

    public $UpdateTime;

    public $Share;

    public $Label;

    public $Switch;

    public $Score;

    public $UseStatus;

    public $HasEasemob;

    public $Sort;

    public $LabelName;

    public $Identified;

    public $GeneralInquiry;

    public $IsSalesman;

    public $Balance;

    public $Money;

    public $OnlineInquiryAmount;

    public $IsDelete;

    public $DoctorSign;

    //验证
    private $selfValidate;

    //自定义验证场景
    //自定义验证场景
    const SCENE_USER_CREATE = 'user-create';
    const SCENE_USER_UPDATE = 'user-update';
    const SCENE_EVALUATE_CREATE = 'evaluate-create';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('UserId', User::class, 'Id', ['alias' => 'User']);
        $this->belongsTo('SectionId', Section::class, 'Id', ['alias' => 'Section']);
    }

    public function getSource()
    {
        return 'OrganizationUser';
    }

    public function validation()
    {
        if ($this->selfValidate) {
            return $this->validate($this->selfValidate);
        }
        return true;
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'OrganizationId' => [new Digit(['message' => '所属机构的格式错误'])],
            'UserId'         => [new Digit(['message' => '用户格式错误'])],
            'Identified'     => [new Digit(['message' => '职业资格证是否认证格式错误'])],
            'IsSalesman'     => [new Digit(['message' => '职业资格证是否认证格式错误'])],
            'Sort'           => [new Between(["minimum" => 0, "maximum" => 9999, "message" => '排序最大不超过9999'])],
            'Role'           => [new PresenceOf(['message' => '请选择角色']), new Digit(['message' => '角色的格式错误'])],
            'Title'          => [new PresenceOf(['message' => '请填选择职称']), new Digit(['message' => '职称的格式错误'])],
            'Intro'          => [new PresenceOf(['message' => '请填写简介'])],
            'Skill'          => [new PresenceOf(['message' => '请填写医生擅长'])],
            'Direction'      => [new PresenceOf(['message' => '请填写研究方向'])],
            'Experience'     => [new PresenceOf(['message' => '请填写工作经历'])],
            'Display'        => [new PresenceOf(['message' => '请选择是否展示']), new Digit(['message' => '是否展示的格式错误'])],
            'LastLoginTime'  => [new Digit(['message' => '最后登录时间的格式错误'])],
            'LastLoginIp'    => [new Digit(['message' => '最后登录ip的格式错误'])],
            'Score'          => [new PresenceOf(['message' => '评分不能为空']), new Digit(['message' => '评分的格式错误'])],
            'LabelName'      => [new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '自定义标签不超过100个字（含空格）'])],
        ];
        $scenes = [
            self::SCENE_USER_CREATE     => ['Title', 'Intro', 'Skill', 'Display', 'OrganizationId', 'Sort', 'LabelName', 'Identified'],
            self::SCENE_USER_UPDATE     => ['Title', 'Intro', 'Skill', 'Display', 'LabelName', 'Identified', 'Sort'],
            self::SCENE_EVALUATE_CREATE => ['Score'],
        ];
        foreach ($scenes[$scene] as $v) {
            $this->selfValidate->rules($v, $fields[$v]);
        }
    }
}
