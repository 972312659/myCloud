<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/13
 * Time: 上午10:19
 */

namespace App\Libs\Order;


use App\Enums\MessageTemplate;
use App\Libs\product\structure\PropertyId;
use App\Libs\Push;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductUnitStatus;
use Phalcon\Di\FactoryDefault;

class Verify
{
    protected $skuIds;
    protected $skuQuantity;
    protected $productMongoIds;
    protected $isMain;
    public $totalAmount;//合计金额(商品金额+包含邮费)
    protected $cloudAmount = 0;//平台手续费
    protected $otherAmount = 0;//其他费用(如果有优惠则加上一个负数)
    protected $postage = 0;//邮费

    public function __construct($sku, $productSphinxResult, $isMain = true)
    {
        foreach ($sku as $item) {
            $this->skuIds[] = $item['SkuId'];
            $this->skuQuantity[$item['SkuId']] = $item['SkuQuantity'];
        }
        if ($productSphinxResult) {
            foreach ($productSphinxResult as $item) {
                $this->productMongoIds[$item['id']] = $item['mongoid'];
            }
        }
        $this->isMain = $isMain;
    }

    public function build($products)
    {
        $result = [];
        if ($products) {
            $productUnitStatus = ProductUnitStatus::query()
                ->columns(['ProductUnitId', 'Stock', 'P.Id as ProductId', 'O.Name as OrganizationName'])
                ->leftJoin(ProductUnit::class, 'S.Id=ProductUnitId', 'S')
                ->leftJoin(Product::class, 'P.Id=S.ProductId', 'P')
                ->leftJoin(Organization::class, 'O.Id=P.OrganizationId', 'O')
                ->inWhere('ProductUnitId', $this->skuIds)
                ->execute();

            $productUnitStatusTmp = [];
            $organization = [];
            foreach ($productUnitStatus as $status) {
                $productUnitStatusTmp[$status->ProductUnitId] = $status->Stock;
                $organization[$status->ProductId] = $status['OrganizationName'];
            }

            //邮费
            $postage = [];
            //单个订单合计金额
            $total = [];

            foreach ($products as $product) {
                $postage[$product->OrganizationId] = isset($postage[$product->OrganizationId]) ? $postage[$product->OrganizationId] : 0;
                $total[$product->OrganizationId] = isset($total[$product->OrganizationId]) ? $total[$product->OrganizationId] : 0;
                /**
                 * @var \App\Libs\product\structure\Product $product
                 */
                $result['Providers'][$product->OrganizationId]['Id'] = $product->OrganizationId;
                $result['Providers'][$product->OrganizationId]['Name'] = $organization[$product->Id];
                foreach ($product->Sku as $sku) {
                    if (in_array($sku->Id, $this->skuIds)) {
                        $price = $this->isMain ? $sku->Price : $sku->PriceForSlave;
                        /**
                         * 邮费规则：单比订单取最大的sku邮费
                         */
                        $postage[$product->OrganizationId] = $postage[$product->OrganizationId] >= $sku->Postage ? $postage[$product->OrganizationId] : $sku->Postage;
                        $total[$product->OrganizationId] += $price * $this->skuQuantity[$sku->Id];
                        $result['Providers'][$product->OrganizationId]['Quantity'] = isset($result['Providers'][$product->OrganizationId]['Quantity']) ? ($result['Providers'][$product->OrganizationId]['Quantity'] + $this->skuQuantity[$sku->Id]) : $this->skuQuantity[$sku->Id];
                        $result['Providers'][$product->OrganizationId]['Products'][] = [
                            'ProductId'  => $product->Id,
                            'SkuId'      => $sku->Id,
                            'Name'       => $product->Name,
                            'Image'      => reset($sku->Images)->Value ?: '',//取sku的第一张图片
                            'Price'      => $price,
                            'Quantity'   => $this->skuQuantity[$sku->Id],
                            'Stock'      => $productUnitStatusTmp[$sku->Id],
                            'Version'    => $this->productMongoIds[$product->Id],
                            'Attributes' => array_map(function (PropertyId $property) {
                                return [
                                    'Name'  => $property->PropertyName,
                                    'Value' => $property->PropertyValueName,
                                ];
                            }, $sku->PropertyIds),
                        ];
                    }
                }
                $result['Providers'][$product->OrganizationId]['Postage'] = $postage[$product->OrganizationId];
                $result['Providers'][$product->OrganizationId]['Total'] = $total[$product->OrganizationId] + $postage[$product->OrganizationId];
            }
        }
        if ($result) {
            foreach ($result as $k => &$value) {
                if ($k == 'Providers') {
                    $value = array_values($value);
                    //得到合计金额
                    $result['Total'] = array_sum(array_column($result['Providers'], 'Total'));
                    $result['Quantity'] = array_sum(array_column($result['Providers'], 'Quantity'));
                    $this->postage = array_sum(array_column($result['Providers'], 'Postage'));
                    $this->totalAmount = $result['Total'];
                    break;
                }
            }
        }
        return $result;
    }

    public function amount()
    {
        return ['totalAmount' => $this->totalAmount, 'cloudAmount' => $this->cloudAmount, 'otherAmount' => $this->otherAmount, 'postage' => $this->postage, 'realAmount' => ($this->totalAmount + $this->cloudAmount + $this->otherAmount)];
    }

    /**
     * 处理订单中库存低于警戒值的提醒
     * @param array $warning
     */
    public function productStockWarning(array $warning)
    {
        foreach ($warning as $item) {
            //大b端消息
            MessageTemplate::send(
                FactoryDefault::getDefault()->get('queue'),
                null,
                MessageTemplate::METHOD_MESSAGE,
                Push::TITLE_STOCK,
                (int)$item['OrganizationId'],
                MessageTemplate::EVENT_PRODUCT_STOCK,
                'product_stock_warning',
                MessageLog::TYPE_PRODUCT_STOCK,
                $item['ProductName'],
                $item['Stock']
            );
        }
    }
}