<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/29
 * Time: 上午9:53
 */

namespace App\Libs\product;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductUnit;

class Validator
{
    public static function product(array $data)
    {
        try {
            //商品名
            if (empty($data['Name'])) {
                throw new LogicException('商品名字不能为空', Status::BadRequest);
            }
            if (strlen($data['Name']) >= 100) {
                throw new LogicException('商品名不能超过100个字符', Status::BadRequest);
            }
            //生产厂商
            if (empty($data['Manufacturer'])) {
                throw new LogicException('生产厂商不能为空', Status::BadRequest);
            }
            if (mb_strlen($data['Manufacturer']) >= 50) {
                throw new LogicException('生产厂商不能超过50个字符', Status::BadRequest);
            }
            //销售属性
            if (!is_array($data['Image']) || empty($data['Image'])) {
                throw new LogicException('请完善商品图片', Status::BadRequest);
            }
            //销售属性
            if (!is_array($data['Properties']) || empty($data['Properties'])) {
                throw new LogicException('请完善商品销售属性', Status::BadRequest);
            }
            //每一个小单元商品
            if (!is_array($data['Sku']) || empty($data['Sku'])) {
                throw new LogicException('请完善商品', Status::BadRequest);
            }
            //描述属性
            if (!is_array($data['Attributes']) || empty($data['Attributes'])) {
                throw new LogicException('请完善商品描述属性', Status::BadRequest);
            }
            $requiredAttributes = Attribute::find([
                'conditions' => 'ProductCategoryId=?0 and IsRequired=?1',
                //ProductCategoryId=1药品 IsRequired=1必填
                'bind'       => [1, 1],
            ]);
            $attribute_temp = [];
            foreach ($data['Attributes'] as $attribute) {
                $attribute_temp[$attribute['Id']] = $attribute['Value'];
            }
            if (count($requiredAttributes->toArray())) {
                foreach ($requiredAttributes as $requiredAttribute) {
                    if (!in_array($requiredAttribute->Id, array_column($data['Attributes'], 'Id'))) {
                        throw new LogicException('请完善商品描述属性-' . $requiredAttribute->Name, Status::BadRequest);
                    }
                    if ($requiredAttribute->Name . ':' . mb_strlen($attribute_temp[$requiredAttribute->Id]) > $requiredAttribute->MaxLength && $requiredAttribute->MaxLength > 0) {
                        throw new LogicException($requiredAttribute->Name . '长度不超多' . $requiredAttribute->MaxLength, Status::BadRequest);
                    }
                }
            }
            $properties = array_column($data['Properties'], 'Id');
            //验证是否选择默认显示商品
            $notSetDefault = true;
            $notSetDefault_total = 0;
            $propertyIds_repeat = [];
            if (count($data['Sku']) == 1) {
                $data['Sku'][0]['IsDefault'] = ProductUnit::IS_DEFAULT_YES;
            }
            foreach ($data['Sku'] as $sku) {
                if (!is_array($sku['PropertyIds']) || empty($sku['PropertyIds'])) {
                    throw new LogicException('每条商品sku的销售属性必须选择一个', Status::BadRequest);
                }
                if (!is_array($sku['Images']) || empty($sku['Images'])) {
                    throw new LogicException('每条商品sku图片至少上传一张', Status::BadRequest);
                }
                if (count($sku['Images']) > 6) {
                    throw new LogicException('每条商品sku图片最多上传六张', Status::BadRequest);
                }
                $str = '';
                foreach ($sku['PropertyIds'] as &$propertyId) {
                    if (in_array($propertyId['PropertyId'], $properties)) {
                        $str .= '(' . $propertyId['PropertyId'] . '-' . ($propertyId['PropertyValueId'] ?: 'null') . ')';
                    } else {
                        $str .= '(' . $propertyId['PropertyId'] . '-null)';
                    }
                }
                $propertyIds_repeat[] = $str;
                if ($sku['IsDefault']) {
                    $notSetDefault = false;
                    $notSetDefault_total += 1;
                }
            }
            if (count($propertyIds_repeat) != count(array_unique($propertyIds_repeat))) {
                throw new LogicException('不能设置相同属性的商品sku', Status::BadRequest);
            }
            if ($notSetDefault) {
                throw new LogicException('未设置默认显示商品sku', Status::BadRequest);
            }
            if ($notSetDefault_total > 1) {
                throw new LogicException('只能设置一个默认显示商品sku', Status::BadRequest);
            }
            if (!is_array($data['Way']) || empty($data['Way'])) {
                throw new LogicException('未选定发布对象', Status::BadRequest);
            }
            foreach ($data['Way'] as $datum) {
                if (!in_array($datum, array_column(Product::WAY_SHOW, 'Id'))) {
                    throw new LogicException('发布对象错误', Status::BadRequest);
                }
            }
        } catch (LogicException $e) {
            throw $e;
        }
    }
}