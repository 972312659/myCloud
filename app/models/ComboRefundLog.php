<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class ComboRefundLog extends Model
{
    //退款申请单状态 1=>待审核 2=>审核通过 3=>审核不通过
    const STATUS_WAIT = 1;
    const STATUS_PASS = 2;
    const STATUS_UNPASS = 3;

    public $Id;
    public $ComboRefundId;
    public $UserId;
    public $UserName;
    public $OrganizationId;
    public $Status;
    public $LogTime;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'ComboRefundLog';
    }

    public function beforeCreate()
    {
        $auth = $this->getDI()->getShared('session')->get('auth');
        $this->LogTime = time();
        $this->OrganizationId = $auth['OrganizationId'];
        $this->UserId = $auth['Id'];
        $this->UserName = $auth['Name'];
    }
}
