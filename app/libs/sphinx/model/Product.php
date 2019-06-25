<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/2
 * Time: 上午11:14
 */

namespace App\Libs\sphinx\model;

use App\Libs\Sphinx;
use App\Models\ProductUnit;
use App\Models\ProductUnitStatus;

class Product
{
    //表名
    const TABLE_NAME = 'product';
    //上下架状态 1=>上架 2=>下架
    const STATUS_ON = 1;
    const STATUS_OFF = 0;
    //商品存在状态 1=>存在并上架 2=>存在但下架 3=>已被删除
    const EXIST_YES_ON = 1;
    const EXIST_YES_OFF = 2;
    const EXIST_NO = 3;

    /**
     * 商品id
     * @var int
     */
    public $id;
    /**
     * 商品名（提交审核的）
     * @var string
     */
    public $submitname;
    /**
     * 商品名
     * @var string
     */
    public $name;
    /**
     * 供应商名称
     * @var string
     */
    public $organizationname;
    /**
     * 生产厂家
     * @var string
     */
    public $manufacturer;
    /**
     * 大B价格
     * @var int
     */
    public $price;
    /**
     * 小B价格
     * @var int
     */
    public $priceforslave;
    /**
     * 总共卖出
     * @var int
     */
    public $total;
    /**
     * 上下架状态
     * @var int
     */
    public $status;
    /**
     * 信用分
     * @var float
     */
    public $creditscore;
    /**
     * mongo版本id
     * @var string
     */
    public $mongoid;
    /**
     * 分类id
     * @var array
     */
    public $categoryid;

    private $sphinx;

    public function __construct(Sphinx $sphinx)
    {
        $this->sphinx = $sphinx;
    }

    /**
     * 新建、修改
     * @param \App\Models\Product $product
     * @param $mongoId
     * @return bool
     */
    public function save(\App\Models\Product $product, $mongoId)
    {
        return $this->sphinx->save($this->sphinxData($product, $mongoId));
    }

    /**
     * @param \App\Models\Product $product
     * @param $mongoId
     * @return array
     */
    public function sphinxData(\App\Models\Product $product, $mongoId): array
    {
        $sphinx_data = [];
        //默认显示的sku
        $sku = ProductUnit::query()
            ->columns(['Id', 'Price', 'PriceForSlave', 'P.Status'])
            ->leftJoin(ProductUnitStatus::class, 'P.ProductUnitId=Id', 'P')
            ->where(sprintf('ProductId=%d', $product->Id))
            ->andWhere(sprintf('IsDefault=%d', ProductUnit::IS_DEFAULT_YES))
            ->execute()[0];
        $sphinx_data['id'] = $product->Id;
        $sphinx_data['submitname'] = $product->Name;
        $sphinx_data['name'] = $product->Name;
        $sphinx_data['organizationname'] = $product->Organization->Name;
        $sphinx_data['manufacturer'] = $product->Manufacturer;
        $sphinx_data['price'] = $sku->Price;
        $sphinx_data['priceforslave'] = $sku->PriceForSlave;
        $sphinx_data['categoryid'] = [$product->ProductCategoryId, $product->ProductCategory->Id];
        $sphinx_data['status'] = $sku->Status;
        $sphinx_data['mongoid'] = $mongoId;
        $product = $this->sphinx->where('=', $product->Id, 'id')->fetch();
        if (!$product) {
            $sphinx_data['total'] = 0;
            $sphinx_data['creditscore'] = 0;
        } else {
            $sphinx_data['total'] = $product['total'];
            $sphinx_data['creditscore'] = $product['creditscore'];
        }
        return $sphinx_data;
    }

    /**
     * 更新字段['status','mongoid','name','submitname']
     * @param int $productId
     * @param $sphinx_data
     * @return bool
     */
    public function update(int $productId, $sphinx_data)
    {
        $product = $this->sphinx->where('=', $productId, 'id')->fetch();
        if ($product) {
            return $this->sphinx->update($sphinx_data, $productId);
        }
        return true;
    }

    /**
     * 删除
     * @param int $productId
     * @return bool
     */
    public function delete(int $productId)
    {
        $product = $this->sphinx->where('=', $productId, 'id')->fetch();
        if ($product) {
            return $this->sphinx->delete($productId);
        }
        return true;
    }

    /**
     * 得到商品mongo中版本主键列表
     * @param array $productIds
     * @return array
     */
    public function getMongoIds(array $productIds): array
    {
        $datas = $this->sphinx->columns('mongoid')->where('in', $productIds, 'id')->fetchAll();
        return $datas ? array_column($datas, 'mongoid') : [];
    }

    /**
     * 得到商品列表
     * @param array $productIds
     * @return array
     */
    public function getList(array $productIds): array
    {
        return $this->sphinx->where('in', $productIds, 'id')->fetchAll();
    }

    /**
     * @param int $productId
     * @return mixed|null
     */
    public function getOne(int $productId)
    {
        return $this->sphinx->where('=', $productId, 'id')->fetch();
    }
}