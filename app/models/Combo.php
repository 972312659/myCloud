<?php

namespace App\Models;

use App\Libs\Sphinx;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\StringLength;
use Phalcon\Validation\Validator\Between;

class Combo extends Model
{
    //发布方式 1=>即时发布 2=>定时发布
    const STYLE_ATONCE = 1;
    const STYLE_CLOCKED = 2;

    //套餐状态
    const STATUS_OFF = 0;   //未发布
    const STATUS_ON = 1;    //已发布
    const STATUS_PASS = 2;  //已过期

    //分润方式 1=>固定 2=>比例 0=>无佣金
    const WAY_FIXED = 1;
    const WAY_RATIO = 2;
    const WAY_NOTHING = 0;

    //是否共享 1=>不共享 2=>共享 3=>等待审核 4=>审核失败 5=>共享关闭（只能由2到5）
    const SHARE_CLOSED = 1;
    const SHARE_SHARE = 2;
    const SHARE_WAIT = 3;
    const SHARE_FAILED = 4;
    const SHARE_PAUSE = 5;

    //审核 0=>待审核 1=>通过 2=>未通过
    const Audit_WAIT = 0;
    const Audit_PASS = 1;
    const Audit_FAILED = 2;

    //操作者 1=>自身 2=>平台
    const OPERATOR_SELF = 1;
    const OPERATOR_PEACH = 2;

    //买家退款 1=>支持 2=>不支持
    const MoneyBack_Yes = 1;
    const MoneyBack_No = 2;

    public $Id;

    public $Name;

    public $Price;

    public $Intro;

    public $CreateTime;

    public $PassTime;

    public $OrganizationId;

    public $Style;

    public $Status;

    public $Author;

    public $IsTop;

    public $ReleaseTime;

    public $Audit;

    public $Share;

    public $Image;

    public $Way;

    public $Amount;

    public $InvoicePrice;

    public $Stock;

    public $MoneyBack;

    public $Operator;

    public $Reason;

    public $OffTime;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
    }

    public function getSource()
    {
        return 'Combo';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Name', [
            new PresenceOf(['message' => '套餐名不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '套餐名字不超过50个字符']),
        ]);
        $validator->rules('Price', [
            new PresenceOf(['message' => '套餐价格不能为空']),
            new Digit(['message' => '套餐价格请填写为数字']),
        ]);
        if ($this->Way == self::WAY_NOTHING) {
            $validator->rules('InvoicePrice', [
                new PresenceOf(['message' => '套餐开票价格不能为空']),
                new Digit(['message' => '套餐开票价格请填写为数字']),
            ]);
        }
        $validator->rule('Intro',
            new PresenceOf(['message' => '套餐介绍不能为空'])
        );
        $validator->rule('Way',
            new PresenceOf(['message' => '请选择佣金方式'])
        );
        $validator->rule('Amount',
            new PresenceOf(['message' => '不能为空'])
        );
        $validator->rule('Amount',
            new Digit(['message' => '佣金金额请填写为数字'])
        );
        $validator->add(['OrganizationId', 'Amount'],
            new Digit([
                'message' => [
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                    'Amount'         => '佣金设置必须为整形数字',
                ],
            ])
        );
        $validator->rules('MoneyBack', [
            new PresenceOf(['message' => '售后服务不能为空']),
            new Digit(['message' => '售后服务格式错误']),
        ]);
        $validator->rule('Reason',
            new StringLength(["min" => 0, "max" => 200, "messageMaximum" => '操作理由不超过200个字符'])
        );
        $validator->rules('IsTop', [
            new Digit(["message" => '排序权重必须是整数']),
            new Between(['minimum' => 0, 'maximum' => 10000, 'message' => '排序权重不能超过10000']),
        ]);
        if (is_numeric($this->Stock)) {
            $validator->rules('Stock', [
                new Digit(["message" => '库存数量必须是整数,且不能为负数']),
                new Between(['minimum' => 0, 'maximum' => 10000000, 'message' => '如果超过10000000个，可选择不限制数量']),
            ]);
        }
        return $this->validate($validator);
    }

    public function afterCreate()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'combo');
        $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
        //创建关联
        if ($this->Status == self::STATUS_ON) {
            $organizationCombo = new OrganizationCombo();
            $organizationCombo->OrganizationId = $this->OrganizationId;
            $organizationCombo->HospitalId = $this->OrganizationId;
            $organizationCombo->ComboId = $this->Id;
            $organizationCombo->save();
        }
    }

    public function beforeUpdate()
    {
        $change = (array)$this->getChangedFields();
        if (in_array('Name', $change, true)) {
            $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'combo');
            $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
        }
        // if (in_array('Share', $change, true)) {
        //     if ($this->Share == Combo::SHARE_SHARE) {
        //         self::updateShareCombos();
        //     }
        // }
        if (in_array('Status', $change, true)) {
            switch ($this->Status) {
                case self::STATUS_OFF:
                    $this->OffTime = time();
                    //删除其他医院采购的套餐
                    $organizationCombos = OrganizationCombo::find([
                        'conditions' => 'ComboId=?0 and HospitalId=?1',
                        'bind'       => [$this->Id, $this->OrganizationId],
                    ]);
                    if (count($organizationCombos->toArray())) {
                        $organizationCombos->delete();
                    }
                    break;
                case self::STATUS_ON:
                    //增加自有
                    $organizationCombo = new OrganizationCombo();
                    $organizationCombo->OrganizationId = $this->OrganizationId;
                    $organizationCombo->HospitalId = $this->OrganizationId;
                    $organizationCombo->Type = OrganizationCombo::TYPE_SELF;
                    $organizationCombo->ComboId = $this->Id;
                    $organizationCombo->Sort = 0;
                    $organizationCombo->save();
                    break;
            }
        }
    }

    public function afterDelete()
    {
        //删除sphinx中相应的套餐
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'combo');
        $sphinx->delete($this->Id);
        //更新sphinx中organization的sharecomboids
        if ($this->Share == Combo::SHARE_SHARE) {
            self::updateShareCombos();
        }
        //删除自身
        $self = OrganizationCombo::findFirst([
            'conditions' => 'ComboId=?0 and OrganizationId=?1',
            'bind'       => [$this->Id, $this->OrganizationId],
        ]);
        if ($self) {
            $self->delete();
        }
        //删除其他医院采购的套餐
        $organizationCombos = OrganizationCombo::find([
            'conditions' => 'ComboId=?0 and HospitalId=?1',
            'bind'       => [$this->Id, $this->OrganizationId],
        ]);
        if (count($organizationCombos->toArray())) {
            $organizationCombos->delete();
        }
    }

    public function afterUpdate()
    {
        self::updateShareCombos();
    }

    /**
     * 处理sphinx organization
     */
    private function updateShareCombos()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'organization');
        $combos = self::find([
            // 'conditions' => 'Status=?0 and Audit=?1 and Share=?2 and PassTime>?3 or PassTime=?4 and OrganizationId=?5',
            // 'bind'       => [1, 1, 2, time(), 0, $this->OrganizationId],
            'conditions' => 'Status=?0 and Audit=?1 and Share=?2 and OrganizationId=?3',
            'bind'       => [1, 1, 2, $this->OrganizationId],
        ])->toArray();
        $sphinx_data['sharecomboids'] = array_column($combos, 'Id');
        $sphinx->update($sphinx_data, $this->OrganizationId);
    }

    /**
     * 处理套餐
     */
    public static function changeStatus($hospitalId)
    {
        $now = time();
        $status = Combo::find([
            'conditions' => 'OrganizationId=?0 and ReleaseTime<=?1 and Status=?2',
            'bind'       => [$hospitalId, $now, self::STATUS_OFF],
        ]);
        if (count($status->toArray())) {
            foreach ($status as $v) {
                $v->Status = self::STATUS_ON;
                $v->save();
                $organizationCombo = new OrganizationCombo();
                $organizationCombo->OrganizationId = $v->OrganizationId;
                $organizationCombo->HospitalId = $v->OrganizationId;
                $organizationCombo->Type = OrganizationCombo::TYPE_SELF;
                $organizationCombo->ComboId = $v->Id;
                $organizationCombo->Sort = 0;
                $organizationCombo->save();
            }
        }
    }

    /**
     * 处理自有套餐
     */
    public static function deal($organizationId, $sphinx)
    {
        $now = time();
        $comboNeedOn = Combo::findFirst([
            'conditions' => 'OrganizationId=?0 and ReleaseTime<=?1',
            'bind'       => [$organizationId, $now],
        ]);
        if ($comboNeedOn) {
            self::changeStatus($organizationId);
        }
        $comboNeedOff = Combo::findFirst([
            'conditions' => 'OrganizationId=?0 and PassTime<=?1 and PassTime!=0',
            'bind'       => [$organizationId, $now],
        ]);
        if ($comboNeedOff) {
            $combos = Combo::find([
                'conditions' => 'OrganizationId=?0 and PassTime<=?1 and PassTime!=0',
                'bind'       => [$organizationId, $now],
            ])->toArray();
            $sphinx = new Sphinx($sphinx, 'organization');
            $comboIds = $sphinx->where('=', $organizationId, 'id')->fetch()['sharesectionids'];
            $comboIds = explode(',', $comboIds);
            $ids = array_column($combos, 'Id');
            $sphinx_data['sharecomboids'] = [];
            foreach ($comboIds as $id) {
                $id = (int)$id;
                if (!in_array($id, $ids)) {
                    $sphinx_data['sharecomboids'][] = $id;
                }
            }
            $sphinx->update($sphinx_data, $organizationId);
            $organizationCombos = OrganizationCombo::query()->inWhere('ComboId', array_column($combos, 'Id'))->execute();
            $organizationCombos->delete();
        }
    }
}
