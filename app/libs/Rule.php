<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/21
 * Time: 上午10:02
 */

namespace App\Libs;


use App\Enums\AreaId;
use App\Models\Organization;
use Phalcon\Mvc\Controller;

class Rule extends Controller
{
    /**
     * @param $provinceId
     * @param $isMain
     * @return int
     */
    public function MerchanCode($provinceId, $isMain)
    {
        $bind = AreaId::value($provinceId) . ($isMain == Organization::ISMAIN_SLAVE ? Organization::ISMAIN_SLAVE : Organization::ISMAIN_HOSPITAL) . '%';
        $last = Organization::findFirst([
            'conditions' => 'MerchantCode like ?0',
            'bind'       => [$bind],
            'order'      => 'MerchantCode desc',
        ]);

        if (!$last) {
            $merchantCode = (int)(AreaId::value($provinceId) . $isMain . '0001');
        } else {
            $value = $last->MerchantCode;

            //前3位为省id+是否为医院（1=>医院，2=>网点),只处理后面
            $codeLengthOld = strlen($value) - 3;
            $codeFront = (int)mb_substr($value, 0, 3);
            $codeEnding = (int)mb_substr($value, 3, $codeLengthOld);
            $code = $codeEnding + 1;
            $codeLengthNew = strlen($code);

            $useLength = $codeLengthNew > $codeLengthOld ? $codeLengthNew : $codeLengthOld;

            $merchantCode = (int)($codeFront . mb_substr(str_repeat(0, $useLength) . $code, -$useLength, $useLength));
        }

        return $merchantCode;
    }

    /**
     * 商品sku商品号规则
     */
    public static function productUnitNumber()
    {
        return date('Ymd') . substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8);
    }

    /**
     * 商品订单号规则
     * @param int $buyerUserId
     * @param int $SellerOrganizationId
     * @return int
     */
    public static function productOrderNumber(int $buyerUserId, int $SellerOrganizationId)
    {
        return time() << 32 | substr('0000000' . $buyerUserId . $SellerOrganizationId, -7, 7);
    }
}