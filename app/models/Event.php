<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class Event extends Model
{
    //事件类型 1=>账户资金 2=>转诊单变化 3=>评价 4=>共享审核 5=>挂号 6=>商城 7=>套餐
    const MONEY = 1;
    const TRANSFER = 2;
    const EVALUATE = 3;
    const SHARE = 4;
    const REGISTRATION = 5;
    const PRODUCT = 6;
    const COMBO = 7;

    public $Id;

    public $Name;

    public $Type;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'Event';
    }
}