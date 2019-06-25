<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class OrganizationSendMessageConfig extends Model
{
    //是否发送消息 1=>发送 2=>不发送
    const AGREE_SEND_YES = 1;
    const AGREE_SEND_NO = 2;

    //类型
    const TYPE_SEND_TO_PATIENT = 1;//1=>网点设置医院接受转诊是否给患者发送短信

    public $Id;

    public $OrganizationId;

    public $AgreeSendMessage;

    public $Type;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
    }

    public function getSource()
    {
        return 'OrganizationSendMessageConfig';
    }
}
