<?php

namespace App\Models;

use App\Enums\RedisName;
use App\Libs\module\ManagerOrganization;
use App\Libs\Sphinx;
use App\Validators\Lat;
use App\Validators\Lng;
use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\StringLength;

class Organization extends Model
{

    //平台的机构id
    const PEACH = 0;

    //审核状态 1=>未审核 2=>审核通过 3=>等待审核 4=>审核失败 5=>关闭共享
    const UNVERIFY = 1;
    const VERIFYED = 2;
    const WAIT = 3;
    const FAIL = 4;
    const CLOSE = 5;

    //区分身份 1=>大b 2=>小b 3=>供应商
    const ISMAIN_HOSPITAL = 1;
    const ISMAIN_SLAVE = 2;
    const ISMAIN_SUPPLIER = 3;

    //1=>综合医院 2=>专科医院 3=>诊所 4=>药店 5=>医务室 6=>个体
    const TYPE_SYNTHESIZE = 1;
    const TYPE_JUNIOR = 2;
    const TYPE_CLINIC = 3;
    const TYPE_DRUGSTORE = 4;
    const TYPE_MEDICAL = 5;
    const TYPE_PERSONALITY = 6;

    public $Id;

    public $Name;

    public $LevelId;

    public $ProvinceId;

    public $CityId;

    public $AreaId;

    public $Address;

    public $Contact;

    public $ContactTel;

    public $Tel;

    public $Phone;

    public $Logo;

    public $Type;

    public $Lat;

    public $Lng;

    public $Balance;

    public $Money;

    public $MerchantCode;

    public $IsMain;

    public $CreateTime;

    public $Verifyed;

    public $RuleId;

    public $TransferAmount;

    public $Score;

    public $Intro;

    public $MachineOrgId;

    public $MachineOrgKey;

    public $Expire;

    public $License;

    public $Fake;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_ORGANIZATION_CREATE = 'organization-create';
    const SCENE_ORGANIZATION_INTRO = 'organization-intro';
    const SCENE_ORGANIZATION_SWITCH = 'organization-switchShare';
    const SCENE_BILL_PAY = 'bill-pay';
    const SCENE_ADMIN_HOSPITAL_CREATE = 'admin-hospital-create';
    const SCENE_SUPPLIER_CREATE = 'supplier-create';

    public function initialize()
    {
        $this->keepSnapshots(true);
        $this->useDynamicUpdate(true);
        $this->belongsTo('ProvinceId', Location::class, 'Id', ['alias' => 'Province']);
        $this->belongsTo('CityId', Location::class, 'Id', ['alias' => 'City']);
        $this->belongsTo('AreaId', Location::class, 'Id', ['alias' => 'Area']);
        $this->hasMany('Id', OrganizationRelationship::class, 'MainId', ['alias' => 'Downstream']);
        $this->hasMany('Id', OrganizationRelationship::class, 'MinorId', ['alias' => 'Upstream']);
        $this->hasMany('Id', Article::class, 'OrganizationId', ['alias' => 'Articles']);
        $this->hasMany('Id', Bill::class, 'OrganizationId', ['alias' => 'Bills']);
        $this->hasMany('Id', Combo::class, 'OrganizationId', ['alias' => 'Combos']);
        $this->hasMany('Id', Pictures::class, 'OrganizationId', ['alias' => 'Pictures']);
        $this->hasMany('Id', OrganizationAndSection::class, 'OrganizationId', ['alias' => 'Sections']);
        $this->hasMany('Id', Transfer::class, 'SendOrganizationId', ['alias' => 'Senders']);
        $this->hasMany('Id', Transfer::class, 'AcceptOrganizationId', ['alias' => 'Accepters']);
        $this->hasMany('Id', TransferLog::class, 'OrganizationId', ['alias' => 'TransferLogs']);
        $this->hasMany('Id', User::class, 'OrganizationId', ['alias' => 'Users']);
        $this->belongsTo('RuleId', RuleOfShare::class, 'Id', ['alias' => 'Rule']);
        $this->hasOne('Id', Hospitalofaptitude::class, 'OrganizationId', ['alias' => 'Aptitude']);
    }

    public function getSource()
    {
        return 'Organization';
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'Phone'          => [new PresenceOf(['message' => '手机号不能为空']), new Mobile(['message' => '请输入正确的手机号']), new Uniqueness(['model' => $this, 'message' => '一个用户只能是一家机构的管理员'])],
            'Name'           => [new PresenceOf(['message' => '请填写名称'])],
            'Contact'        => [new PresenceOf(['message' => '请填写联系人'])],
            'ContactTel'     => [new PresenceOf(['message' => '请填写联系方式'])],
            'Tel'            => [new StringLength(["min" => 0, "max" => 13, "messageMaximum" => '客服电话最长不超过13位'])],
            'Type'           => [new PresenceOf(['message' => '请选择类型']), new Digit(['message' => '类型的格式错误'])],
            'ProvinceId'     => [new PresenceOf(['message' => '请选择省份']), new Digit(['message' => '省份的格式错误'])],
            'CityId'         => [new PresenceOf(['message' => '请选择城市']), new Digit(['message' => '城市的格式错误'])],
            'AreaId'         => [new PresenceOf(['message' => '请选择地区']), new Digit(['message' => '地区的格式错误'])],
            'Address'        => [new PresenceOf(['message' => '请填写详细地址'])],
            'LevelId'        => [new PresenceOf(['message' => '请填写等级']), new Digit(['message' => '等级的格式错误'])],
            'Lat'            => [new PresenceOf(['message' => '请填写纬度']), new Lat(["message" => "纬度格式错误"])],
            'Lng'            => [new PresenceOf(['message' => '请填写经度']), new Lng(["message" => "经度格式错误"])],
            'RuleId'         => [new PresenceOf(['message' => '请选择佣金规则']), new Digit(['message' => '佣金规则的格式错误'])],
            'TransferAmount' => [new PresenceOf(['message' => '完成转诊数量不能为空']), new Digit(['message' => '完成转诊数量的格式错误'])],
            'Money'          => [new PresenceOf(['message' => '账户可提现金额不能为空']), new Digit(['message' => '账户可提现金额的格式错误'])],
            'Balance'        => [new PresenceOf(['message' => '账户余额不能为空']), new Digit(['message' => '账户余额的格式错误'])],
            'Score'          => [new PresenceOf(['message' => '评分不能为空']), new Digit(['message' => '评分的格式错误'])],
            'Logo'           => [new StringLength(["min" => 0, "max" => 255, "messageMaximum" => '最长不超过255'])],
            'Verifyed'       => [new PresenceOf(['message' => '共享开关不能为空']), new Digit(['message' => '评分的格式错误'])],
            'MerchantCode'   => [new Uniqueness(['message' => '机构号码不能重复'])],
        ];
        $scenes = [
            self::SCENE_ORGANIZATION_CREATE   => ['Name', 'Type', 'Contact', 'ProvinceId', 'CityId', 'AreaId', 'Phone', 'MerchantCode'],
            self::SCENE_ORGANIZATION_INTRO    => ['Logo', 'Tel'],
            self::SCENE_ORGANIZATION_SWITCH   => ['Verifyed'],
            self::SCENE_BILL_PAY              => ['TransferAmount', 'Money', 'Balance'],
            self::SCENE_ADMIN_HOSPITAL_CREATE => ['Name', 'LevelId', 'Type', 'Contact', 'ContactTel', 'ProvinceId', 'CityId', 'AreaId', 'Lat', 'Lng', 'MerchantCode'],
            self::SCENE_SUPPLIER_CREATE       => ['Name', 'LevelId', 'Type', 'Contact', 'ContactTel', 'ProvinceId', 'CityId', 'AreaId', 'Lat', 'Lng', 'MerchantCode'],
        ];
        foreach ($scenes[$scene] as $v) {
            $this->selfValidate->rules($v, $fields[$v]);
        }
    }

    public function validation()
    {
        if ($this->selfValidate) {
            return $this->validate($this->selfValidate);
        }
        return true;
    }

    public function beforeCreate()
    {
        $this->CreateTime = time();
        $this->Money = 0;
        $this->Balance = 0;
    }

    public function afterCreate()
    {
        $changed = (array)$this->getChangedFields();
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null && !in_array('Verifyed', $changed, true)) {
            $this->staffHospitalLog(StaffHospitalLog::CREATE);
        }
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (count($changed)) {
            $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'organization');
            if (in_array('Name', $changed) || in_array('Contact', $changed)) {
                $data = $sphinx->where('=', (int)$this->Id, 'id')->fetch();
                $sphinx_data = [
                    'id'              => $this->Id,
                    'name'            => $this->Name,
                    'alias'           => $data['alias'] ?: $this->Name,
                    'contact'         => $this->Contact,
                    'provinceid'      => (int)$this->ProvinceId ?: 0,
                    'cityid'          => (int)$this->CityId ?: 0,
                    'areaid'          => (int)$this->AreaId ?: 0,
                    'ismain'          => (int)$this->IsMain ?: 0,
                    'transferamount'  => (int)$this->TransferAmount ?: 0,
                    'type'            => (int)$this->Type ?: 0,
                    'lat'             => (float)$this->Lat ?: 0,
                    'lng'             => (float)$this->Lng ?: 0,
                    'score'           => (float)$this->Score ?: 0,
                    'pids'            => empty($data['pids']) ? [] : explode(',', $data['pids']),
                    'sharesectionids' => empty($data['sharesectionids']) ? [] : explode(',', $data['sharesectionids']),
                    'sharecomboids'   => empty($data['sharecomboids']) ? [] : explode(',', $data['sharecomboids']),
                ];
                $sphinx->save($sphinx_data);
            } elseif (count(array_intersect($changed, ['ProvinceId', 'CityId', 'AreaId', 'IsMain', 'TransferAmount', 'Type', 'Lat', 'Lng', 'Score'])) && $this->Id != self::PEACH) {
                $sphinx_data = [
                    'provinceid'     => (int)$this->ProvinceId ?: 0,
                    'cityid'         => (int)$this->CityId ?: 0,
                    'areaid'         => (int)$this->AreaId ?: 0,
                    'ismain'         => (int)$this->IsMain ?: 0,
                    'transferamount' => (int)$this->TransferAmount ?: 0,
                    'type'           => (int)$this->Type ?: 0,
                    'lat'            => (float)$this->Lat ?: 0,
                    'lng'            => (float)$this->Lng ?: 0,
                    'score'          => (float)$this->Score ?: 0,
                ];
                $sphinx->update($sphinx_data, $this->Id);
            }
        }
        if ($this->getDI()->getShared('session')->get('auth') && $this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null && !in_array('Verifyed', $changed, true)) {
            $this->staffHospitalLog(StaffHospitalLog::UPDATE);
        }
        if (in_array('IsMain', $changed, true)) {
            if ($this->IsMain == self::ISMAIN_HOSPITAL) {
                self::updateShare();
            }
        }
        //清除权限缓存
        if (in_array('Expire', $changed, true)) {
            $organizationUsers = OrganizationUser::find([
                'conditions' => 'OrganizationId=?0',
                'bind'       => [$this->Id],
            ])->toArray();
            //更新模块
            if ($this->IsMain == self::ISMAIN_HOSPITAL) {
                ManagerOrganization::updateOrganizationModule($this->Id, $this->Expire, $this->getDI()->getShared('session')->get('auth')['Name']);
            }

            $ids = array_column($organizationUsers, 'UserId');
            foreach ($ids as $id) {
                $this->getDI()->getShared('redis')->delete(RedisName::Permission . $this->Id . '_' . $id);
            }
        }
    }

    public function beforeDelete()
    {
        $changed = (array)$this->getChangedFields();
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null && !in_array('Verifyed', $changed, true)) {
            $this->staffHospitalLog(StaffHospitalLog::DELETE);
        }
    }

    private function staffHospitalLog($status)
    {
        $staffHospitalLog = new StaffHospitalLog();
        $staffHospitalLog->StaffId = $this->getDI()->getShared('session')->get('auth')['Id'];
        $staffHospitalLog->OrganizationId = $this->Id;
        $staffHospitalLog->Created = time();
        $staffHospitalLog->Operated = $status;
        $staffHospitalLog->save();
    }

    /**
     * 处理sphinx organization
     */
    private function updateShare()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'organization');
        $combos = Combo::find([
            'conditions' => 'Status=?0 and Audit=?1 and Share=?2 and PassTime>?3 or PassTime=?4 and OrganizationId=?5',
            'bind'       => [1, 1, 2, time(), 0, $this->Id],
        ])->toArray();
        $sphinx_data['sharecomboids'] = array_column($combos, 'Id');
        $sections = OrganizationAndSection::find([
            'conditions' => 'Display=?0 and Share=?1 and OrganizationId=?2',
            'bind'       => [OrganizationAndSection::DISPLAY_ON, OrganizationAndSection::SHARE_SHARE, $this->Id,],
        ])->toArray();
        $sphinx_data['sharesectionids'] = array_column($sections, 'Id');
        $sphinx->update($sphinx_data, $this->Id);
    }
}
