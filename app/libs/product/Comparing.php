<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/19
 * Time: 下午1:02
 */

namespace App\Libs\product;


use App\Libs\product\diff\DiffEngine;
use App\Libs\product\diff\Diff;
use App\Libs\product\structure\Product;

class Comparing
{
    private $changed = false;

    public function diff(Product $product_old, Product $product_new)
    {
        $engine = new DiffEngine();
        $diff = $engine->compare($product_old, $product_new);
        $trace = function ($diff, $tab = '', $tier = 1) use (&$trace) {
            foreach ($diff as $element) {
                /**
                 * @var Diff $element
                 */
                $c = $element->isTypeChanged() ? 'T' : ($element->isModified() ? 'M' : ($element->isCreated() ? '+' : ($element->isDeleted() ? '-' : '=')));

                // print_r(sprintf("%s* %s [%s -> %s] (%s)\n", $tab, $element->getIdentifier(), is_object($element->getOld())?get_class($element->getOld()):gettype($element->getOld()), is_object($element->getNew())?get_class($element->getNew()):gettype($element->getNew()), $c));
                // print_r(sprintf("%s* %s [%s -> %s] (%s)\n", $tab, $element->getIdentifier(), gettype($element->getOld()), gettype($element->getNew()), $c));
                switch ($c) {
                    case 'M':
                        $this->changed = true;
                        break;
                    case '+':
                        $this->changed = true;
                        break;
                }
                if ($diff->isModified()) {
                    $trace($element, $tab . '  ', $tier + 1);
                }
            }
        };
        $trace($diff);
        return $this->changed;
    }

    public function filterSkuProperty($product, $filter = null)
    {
        $propertyValueIds = [];
        $group = [];
        foreach ($product['Sku'] as $k => $sku) {
            $propertyValueIds = array_merge($propertyValueIds, array_column($sku['PropertyIds'], 'PropertyValueId'));
            $temp = [];
            foreach ($sku['PropertyIds'] as $propertyId) {
                $temp[$propertyId['PropertyId']] = $propertyId['PropertyValueId'];
            }
            $group[$k] = $temp;
        }
        $choose = false;
        $chooseArray = [];
        $chooseProperty = [];
        if (count($filter) >= 1) {
            $choose = true;
            $chooseArray = array_column($filter[' Properties'], 'PropertyValue');
            $chooseProperty = array_column($filter['Properties'], 'Id');
        }
        $skuPropertyKey = false;
        if ($filter === null || count($filter) <= 1) {
            foreach ($product['Properties'] as $property) {
                foreach ($property['PropertyValues'] as &$value) {
                    $value['Enable'] = in_array($value['Id'], $propertyValueIds) ? true : false;
                    if ($choose && in_array($value['Id'], $chooseArray)) {
                        $value['Choosed'] = true;
                        foreach ($group as $k => $item) {
                            if ($item == [$value['Id']]) {
                                $skuPropertyKey = $k;
                            }
                        }
                    }
                }
            }
        } else {
            foreach ($product['Properties'] as $property) {
                $choosePropertyTmp = $chooseProperty;
                unset($choosePropertyTmp[array_search($property['Id'], $choosePropertyTmp)]);
                foreach ($property['PropertyValues'] as &$value) {
                    if (!in_array($property['Id'], $chooseProperty)) {
                        //已选择属性以外的
                        $group_tem = array_merge($chooseProperty, [$value['Id']]);
                    } else {
                        //已选择的
                        if (in_array($value['Id'], $chooseArray)) {
                            $value['Choosed'] = true;
                        }
                        $group_tem = array_merge($choosePropertyTmp, [$value['Id']]);
                    }
                    foreach ($group as $k => $item) {
                        if ($group_tem == array_intersect($group_tem, $item)) {
                            $value['Enable'] = true;
                            if ($item == array_intersect($item, $group_tem)) {
                                $skuPropertyKey = $k;
                            }
                        } else {
                            $value['Enable'] = false;
                        }
                    }
                }
            }
        }
        if ($skuPropertyKey) {
            $product['Sku'] = $product['Sku'][$skuPropertyKey];
        } else {
            $product['Sku'] = [];
        }
        return $product;
    }

    /**
     * @param $product
     * @param null $filter = [{
     *                     Id: 'DP001',  //属性id(如规格的id)
     *                     PropertyValue: 'DPP001' //属性值id(如规格20mg*10片的id)
     *                     }, {
     *                     Id: 'DP002',
     *                     PropertyValue: 'DPP003'
     *                     }]
     */
    public function property($product, $filter = null)
    {
        $choosed = [];
        $line = [];
        if (!$filter) {
            $choosed = array_column($filter, 'PropertyValue');
            $line = array_column($filter, 'Id');
        }
        $productSkuGroup = [];
        foreach ($product['Sku'] as $item) {
            $tmpIds = array_column($item['PropertyIds'], 'PropertyValueId');
            foreach ($tmpIds as $k => $id) {
                $tmp_new = $tmpIds;
                unset($tmp_new[$k]);
                $productSkuGroup[$id] = array_merge($productSkuGroup[$id], $tmp_new);
            }
        }
        foreach ($productSkuGroup as &$group) {
            $group = array_unique($group);
        }
        foreach ($product['Properties'] as $property) {
            $choosed_tmp = [];
            if (in_array($property['Id'], $line)) {
                $choosed_tmp = array_search(array_intersect(array_column($property['PropertyValues'], 'Id'), $choosed)[0], $choosed);
            }
            foreach ($property['PropertyValues'] as &$propertyValue) {
                $value_arr = $productSkuGroup[$propertyValue['Id']];
                if ($choosed) {
                    if (in_array($propertyValue['Id'], $choosed)) {
                        $propertyValue['Choosed'] = true;
                    }
                    if (count($choosed) > 1) {
                        if (isset($value_arr) && is_array($value_arr) && count($value_arr) > 0) {
                            $propertyValue['Enable'] = false;
                            if (in_array($property['Id'], $line)) {
                                if ($value_arr == array_intersect($value_arr, $choosed_tmp)) {
                                    $propertyValue['Enable'] = true;
                                }
                            } else {
                                if ($value_arr == array_intersect($value_arr, $choosed)) {
                                    $propertyValue['Enable'] = true;
                                }
                            }
                        }
                    } else {
                        if (in_array($property['Id'], $line)) {
                            $propertyValue['Enable'] = (isset($value_arr) && is_array($value_arr) && count($value_arr) > 0) ? true : false;
                        } else {
                            $propertyValue['Enable'] = (isset($value_arr) && is_array($value_arr) && count($value_arr) > 0 && in_array($choosed[0], $value_arr)) ? true : false;
                        }
                    }
                } else {
                    $propertyValue['Enable'] = (isset($value_arr) && is_array($value_arr) && count($value_arr) > 0) ? true : false;
                }
            }
        }
    }
}