<?php

namespace App\Libs\fake\models;

use Phalcon\Mvc\Model;

/**
 * Class TransferLog
 * @package App\Libs\fake\transfer
 *
 * @property int OrganizationId
 * @property string OrganizationName
 * @property int UserId
 * @property string UserName
 * @property int TransferId
 * @property int Status
 * @property string Info
 * @property int LogTime
 * @property Model Transfer
 */
class TransferLog extends Model
{
    public function initialize()
    {
        $this->belongsTo('TransferId', Transfer::class, 'id', [
            'alias' => 'Transfer'
        ]);
    }

    public function getSource()
    {
        return 'TransferLog';
    }
}
