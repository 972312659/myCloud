<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/4
 * Time: 下午3:47
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class InteriorTradeAndOrder extends Model
{
    public $InteriorTradeId;

    public $OrderId;

    public $Amount;

    public $ShareCloud;


    public function initialize()
    {
        $this->belongsTo('InteriorTradeId', InteriorTrade::class, 'Id', ['alias' => 'InteriorTrade']);
        $this->belongsTo('OrderId', Order::class, 'Id', ['alias' => 'Order']);
    }

    public function getSource()
    {
        return 'InteriorTradeAndOrder';
    }
}