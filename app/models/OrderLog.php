<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class OrderLog extends Model
{
    public $Id;

    public $PreStatus;

    public $CurrentStatus;

    public $SellerId;

    public $BuyerId;

    public $Message;

    public $Created;

    public $OrderId;

    public function initialize()
    {
        $this->belongsTo('OrderId', Order::class, 'Id', ['alias' => 'Order']);
    }

    public function getSource()
    {
        return 'OrderLog';
    }
}
