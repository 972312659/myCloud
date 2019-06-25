<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use App\Libs\Sphinx;
use Phalcon\Mvc\Model;

class SicknessAndOrganization extends Model
{
    //是否上架 0=>下架 1=>上架
    const STATUS_OFF = 0;
    const STATUS_ON = 1;

    public $SicknessSectionId;

    public $SicknessId;

    public $OrganizationId;

    public $OrganizationSectionId;

    public $Status;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'SicknessAndOrganization';
    }

    public function beforeCreate()
    {
        $this->Status = self::STATUS_ON;
    }

    public function afterCreate()
    {
        $this->sicknessSphinx(true);
    }

    public function beforeUpdate()
    {
        if ($this->Status == null) {
            $this->Status = self::STATUS_ON;
        }
    }

    public function afterUpdate()
    {
        $this->sicknessSphinx(false);
    }

    public function afterDelete()
    {
        $this->sicknessSphinx(false);
    }

    protected function sicknessSphinx($create)
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'sickness');
        $result = $sphinx->where('=', (int)$this->SicknessId, 'id')->fetch();
        $organizations = [];
        if (!empty($result['organizations'])) {
            $organizations = explode(',', $result['organizations']);
        }
        if ($create) {
            $organizations[] = $this->OrganizationId;
        } else {
            if (count($organizations) && in_array($this->OrganizationId, $organizations)) {
                $relation = self::findFirst([
                    'conditions' => 'OrganizationId=?0 and SicknessId=?1',
                    'bind'       => [$this->OrganizationId, $this->SicknessId],
                ]);
                if (!$relation) {
                    foreach ($organizations as $k => $organization) {
                        if ($organization == $this->OrganizationId) {
                            unset($organizations[$k]);
                        }
                    }
                }
            }
        }
        $sphinx_data['organizations'] = $organizations;
        $sphinx->update($sphinx_data, $this->SicknessId);
    }
}