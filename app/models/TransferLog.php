<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class TransferLog extends Model
{
    public $Id;

    public $OrganizationId;

    public $OrganizationName;

    public $UserId;

    public $UserName;

    public $TransferId;

    public $Status;

    public $Info;

    public $LogTime;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('TransferId', Transfer::class, 'Id', ['alias' => 'Transfer']);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
    }

    public function getSource()
    {
        return 'TransferLog';
    }

    public static function addLog(int $organizationId, string $organizationName, int $userId, string $userName, $transferId, int $status, int $time = 0)
    {
        $log = new TransferLog();
        $log->OrganizationId = $organizationId;
        $log->OrganizationName = $organizationName;
        $log->UserId = $userId;
        $log->UserName = $userName;
        $log->TransferId = $transferId;
        $log->Status = $status;
        $log->LogTime = $time;
        $log->save();
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule(['OrganizationId', 'TransferId', 'UserId'],
            new Digit([
                'message' => [
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                    'TransferId'     => 'TransferId必须为整形数字',
                    'UserId'         => 'UserId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }
}