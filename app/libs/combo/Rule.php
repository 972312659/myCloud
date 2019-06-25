<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/14
 * Time: 11:45 AM
 */

namespace App\Libs\combo;


class Rule
{
    public static function orderNumber($buyerOrganizationId)
    {
        return time() << 32 | substr('0000000' . $buyerOrganizationId, -7, 7);
    }
}