<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/8/13
 * Time: 下午2:30
 */

namespace App\Enums;


class TransferHint
{
    //自有转诊
    const UnMatchingProfitRule_Self = '没有相应的规则匹配，请在%s所在的分组（%s）中完善规则';
    //共享转诊
    const UnMatchingProfitRule_Share = '需要在分润规则中设置不限制分组的规则';
}