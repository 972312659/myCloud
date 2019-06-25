<?php

namespace App\Libs\fake\models;

use Phalcon\Mvc\Model;

/**
 * Class TransferPicture
 * @package App\Libs\fake\transfer
 *
 * @property int TransferId
 * @property int Type
 * @property string Image
 */
class TransferPicture extends Model
{
    public function initialize()
    {
        $this->belongsTo('TransferId', Transfer::class, 'Id', [
            'alias' => 'transfer'
        ]);
    }

    public function getSource()
    {
        return 'TransferPicture';
    }
}
