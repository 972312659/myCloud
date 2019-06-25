<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/10
 * Time: 17:39
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class TradeLog extends Model
{
    public $Id;

    public $TradeId;

    public $UserId;

    public $StatusBefore;

    public $StatusAfter;

    public $Reason;

    public $Created;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('TradeId', Trade::class, 'Id', ['alias' => 'Trade']);
    }

    public function getSource()
    {
        return 'TradeLog';
    }
}