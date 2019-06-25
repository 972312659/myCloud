<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class Pictures extends Model
{
    use ValidationTrait;

    //图片类型
    const TYPE_BANNER_APP = 1;
    const TYPE_BANNER_WEB = 2;
    const TYPE_TRANSFER_SICK_CASE = 3;
    const TYPE_TRANSFER_COST = 4;
    const TYPE_EQUIPMENT = 5;
    const TYPE_EQUIPMENT_VOUCHER = 6;

    public $Id;

    public $OrganizationId;

    public $TransferId;

    public $EquipmentId;

    public $SectionId;

    public $Type;

    public $Path;

    public $Intro;

    public $Url;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('TransferId', Transfer::class, 'Id', ['alias' => 'Transfer']);
        $this->belongsTo('EquipmentId', OrganizationAndEquipment::class, 'Id', ['alias' => 'Equipment']);
    }

    public function getSource()
    {
        return 'Pictures';
    }
}