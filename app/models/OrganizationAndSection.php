<?php

namespace App\Models;

use App\Libs\Sphinx;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\Between;

class OrganizationAndSection extends Model
{
    //是否共享 1=>不共享 2=>共享  3=>等待审核 4=>审核失败 5=>共享关闭（只能由2到5）
    const SHARE_CLOSED = 1;
    const SHARE_SHARE = 2;
    const SHARE_WAIT = 3;
    const SHARE_FAILED = 4;
    const SHARE_PAUSE = 5;

    //显示状态 1=>显示 2=>不显示
    const DISPLAY_ON = 1;
    const DISPLAY_OFF = 2;

    public $OrganizationId;

    public $SectionId;

    public $IsSpecial;

    public $Rank;

    public $Display;

    public $Share;

    public $UpdateTime;

    private $change = false;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('SectionId', Section::class, 'Id', ['alias' => 'Section']);
    }

    public function getSource()
    {
        return 'OrganizationAndSection';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule(["OrganizationId", "SectionId"],
            new Uniqueness(['model' => $this, 'message' => '科室已存在'])
        );
        $validator->rule(['OrganizationId', 'SectionId'],
            new Digit([
                'message' => [
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                    'SectionId'      => 'SectionId必须为整形数字',
                ],
            ])
        );
        $validator->rule(['Rank'],
            new Between([
                "minimum" => 0,
                "maximum" => 9999,
                "message" => '最大不超过9999',
            ]));
        return $this->validate($validator);
    }

    public function beforeUpdate()
    {
        $change = (array)$this->getChangedFields();
        if (in_array('Share', $change, true) || in_array('Display', $change, true)) {
            $this->change = true;
        }
    }

    public function afterUpdate()
    {
        if ($this->change) {
            //更新sphinx
            self::updateShareSections();
            //更新采购表
            if ($this->Display == self::DISPLAY_OFF) {
                $organization = Organization::findFirst(sprintf('Id=%d', $this->OrganizationId));
                if ($organization->IsMain == Organization::ISMAIN_HOSPITAL) {
                    self::deleteOrganizationSections();
                }
            } else {
                self::createOrganizationSection();
            }
        }
    }

    public function afterCreate()
    {
        //创建关联
        if ($this->Display == self::DISPLAY_ON) {
            self::createOrganizationSection();
        }
    }

    public function afterDelete()
    {
        //删除其他医院采购的科室
        self::deleteOrganizationSections();
        if ($this->Share == self::SHARE_SHARE) {
            self::updateShareSections();
        }
        //删除重疾对应的医院科室
        self::deleteSicknessAndOrganization();
        //删除该科室对应的转诊分润规则
        self::deleteProfitRule();
    }

    /**
     * 处理sphinx organization
     */
    private function updateShareSections()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'organization');
        $sections = self::find([
            'conditions' => 'Display=?0 and Share=?1 and OrganizationId=?2',
            'bind'       => [self::DISPLAY_ON, self::SHARE_SHARE, $this->OrganizationId],
        ])->toArray();
        $sphinx_data['sharesectionids'] = array_column($sections, 'SectionId');
        $sphinx->update($sphinx_data, $this->OrganizationId);
    }

    /**
     * 删除其他医院采购本科室
     */
    private function deleteOrganizationSections()
    {
        $self = OrganizationSection::findFirst([
            'conditions' => 'SectionId=?0 and OrganizationId=?1 and HospitalId=?2',
            'bind'       => [$this->SectionId, $this->OrganizationId, $this->OrganizationId],
        ]);
        if ($self) {
            $self->delete();
        }
        $organizationSection = OrganizationSection::find([
            'conditions' => 'SectionId=?0 and HospitalId=?1',
            'bind'       => [$this->SectionId, $this->OrganizationId],
        ]);
        if (count($organizationSection->toArray())) {
            $organizationSection->delete();
        }
    }

    /**
     * 删除重疾对应的医院科室
     */
    private function deleteSicknessAndOrganization()
    {
        $sicknessAndOrganizations = SicknessAndOrganization::find([
            'conditions' => 'OrganizationSectionId=?0 and OrganizationId=?1',
            'bind'       => [$this->SectionId, $this->OrganizationId],
        ]);
        if (count($sicknessAndOrganizations->toArray())) {
            $sicknessAndOrganizations->delete();
        }
    }

    /**
     * 删除该科室对应的转诊分润规则
     */
    private function deleteProfitRule()
    {
        $profitRules = ProfitRule::find([
            'conditions' => 'SectionId=?0 and OrganizationId=?1',
            'bind'       => [$this->SectionId, $this->OrganizationId],
        ]);
        if (count($profitRules->toArray())) {
            $profitRules->delete();
        }
    }

    /**
     * 创建关联
     */
    public function createOrganizationSection()
    {
        $old = OrganizationSection::findFirst([
            'conditions' => 'OrganizationId=?0 and SectionId=?1 and HospitalId=?2',
            'bind'       => [$this->OrganizationId, $this->SectionId, $this->OrganizationId],
        ]);
        if ($old) {
            $this->Rank = $old->Sort;
        }
        if (!$old && $this->Display == self::DISPLAY_ON) {
            $organizationSection = new OrganizationSection();
            $organizationSection->OrganizationId = $this->OrganizationId;
            $organizationSection->HospitalId = $this->OrganizationId;
            $organizationSection->SectionId = $this->SectionId;
            $organizationSection->Type = OrganizationSection::TYPE_SELF;
            $organizationSection->Sort = $this->Rank;
            $organizationSection->save();
        }
    }
}
