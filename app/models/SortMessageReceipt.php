<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2018/12/28
 * Time: 11:45
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class SortMessageReceipt extends Model
{
    public $Id;

    public $Created;

    public $Phone;

    public $ReceiptMessage;

    public function getSource()
    {
        return 'SortMessageReceipt';
    }
}