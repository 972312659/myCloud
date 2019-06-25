<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class EquipmentAndSection extends Model
{
    public $OrganizationId;

    public $EquipmentId;

    public $SectionId;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('EquipmentId', Equipment::class, 'Id', ['alias' => 'Equipment']);
        $this->belongsTo('SectionId', Section::class, 'Id', ['alias' => 'Section']);
    }

    public function getSource()
    {
        return 'EquipmentAndSection';
    }
}
