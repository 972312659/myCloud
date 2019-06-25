<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class ShoppingCart extends Model
{
    public $OwnerId;

    public $Content;

    public function getSource()
    {
        return 'ShoppingCart';
    }
}
