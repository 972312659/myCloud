<?php

namespace App\Libs\request\order;

class Feedback
{
    /**
     * @var int
     */
    public $OrderId;

    /**
     * @var string
     */
    public $Feedback;

    /**
     * @var bool
     */
    public $IsRefund;
}
