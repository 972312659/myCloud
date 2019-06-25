<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/23
 * Time: 下午3:36
 */

namespace App\Libs\product;

use App\Enums\Mongo;
use App\Libs\product\structure\Attribute;
use App\Libs\product\structure\Image;
use App\Libs\product\structure\Product;
use App\Libs\product\structure\Property;
use App\Libs\product\structure\PropertyId;
use App\Libs\product\structure\PropertyValue;
use App\Libs\product\structure\Sku;
use App\Models\ProductUnit;
use MongoDB\Database;

class Mapper
{
    private $mongo;

    public function __construct(Database $db)
    {
        $this->mongo = $db;
    }

    public static function toJson(Product $product): string
    {
        return json_encode($product, JSON_UNESCAPED_UNICODE);
    }

    /**
     * json字符串获取product结构体
     * @param string
     * @return object
     */
    public function jsonToProduct(string $json)
    {
        $json = json_decode($json);
        $mapper = new \JsonMapper();
        return $mapper->map($json, new Product());
    }

    /**
     * 通过商品Id或者skuId获取product结构体
     * @param int|null $productId
     * @param int|null $skuId
     * @return null|object
     */
    public function mongoToProduct($productId, int $skuId = null)
    {
        if ($skuId) {
            /**
             * @var ProductUnit $productUnit
             */
            $productUnit = ProductUnit::findFirst(sprintf('Id=%d', $skuId));
            $productId = $productUnit ? $productUnit->ProductId : $productId ?: null;
        }
        $json = $this->mongo->selectCollection(Mongo::collection_product)->find(['Id' => $productId], ['sort' => ['_id' => -1], 'limit' => 1])->toArray()[0] ?: null;
        $mapper = new \JsonMapper();
        return $json === null ? null : $mapper->map($json, new Product());
    }

    /**
     * 通过商品Id或者skuId获取mongodb中商品主键_id
     * @param int|null $productId
     * @param int|null $skuId
     * @return mixed|null
     */
    public function getMongoId(int $productId, int $skuId = null)
    {
        if ($skuId) {
            /**
             * @var ProductUnit $productUnit
             */
            $productUnit = ProductUnit::findFirst(sprintf('Id=%d', $skuId));
            $productId = $productUnit ? $productUnit->ProductId : $productId ?: null;
        }
        $json = $this->mongo->selectCollection(Mongo::collection_product)->find(['Id' => $productId], ['sort' => ['_id' => -1], 'limit' => 1])->toArray()[0];
        return $json ? $json->_id->__toString() : null;
    }

    /**
     * 通过MongoDb主键获取product结构体
     * @param string $_id
     * @return null|object
     */
    public function mongoIdToProduct(string $_id)
    {
        $id = new \MongoDB\BSON\ObjectId($_id);
        $json = $this->mongo->selectCollection(Mongo::collection_product)->findOne(['_id' => $id]) ?: null;
        $mapper = new \JsonMapper();
        return $json === null ? null : $mapper->map($json, new Product());
    }

    /**
     * 获得结构体
     * @param int $id 商品id
     * @return Product
     */
    public static function product(int $id): Product
    {
        $structure_product = new Product();
        /**
         * @var \App\Models\Product $product
         */
        $product = \App\Models\Product::findFirst(sprintf('Id=%d', $id));
        $structure_product->OrganizationId = $product->OrganizationId;
        $structure_product->Id = $product->Id;
        $structure_product->Name = $product->Name;
        $structure_product->Manufacturer = $product->Manufacturer;
        $structure_product->Description = $product->Description;
        $structure_product->Way = $product->Way;
        $structure_product->ProductCategoryId = $product->ProductCategoryId;
        //Image
        $productPictures = \App\Models\ProductPicture::query()
            ->columns(['Id', 'Image'])
            ->where('ProductId=:ProductId:')
            ->bind(['ProductId' => $product->Id])
            ->execute();
        foreach ($productPictures as $picture) {
            /**
             * @var \App\Models\ProductPicture $picture
             */
            $structure_image = new Image();
            $structure_image->Id = $picture->Id;
            $structure_image->Value = $picture->Image;
            $structure_product->Image[] = $structure_image;
        }
        //Properties
        $properties = \App\Models\Property::query()
            ->columns(['Id', 'Name'])
            ->join(\App\Models\ProductProperty::class, 'P.PropertyId=Id', 'P')
            ->where(sprintf('P.ProductId=%d', $product->Id))
            ->execute();
        $productPropertyValues = \App\Models\ProductPropertyValue::find([
            'conditions' => 'ProductId=?0',
            'bind'       => [$product->Id],
        ]);
        $productPropertyValues_new = [];
        $productPropertyValues_forSku = [];
        $properties_forSku = [];
        $property_value_obj = [];
        foreach ($productPropertyValues as $productPropertyValue) {
            /**
             * @var \App\Models\ProductPropertyValue $productPropertyValue
             */
            $result['Properties'][$productPropertyValue->PropertyId][] = $productPropertyValue->Value;
            $productPropertyValues_new[$productPropertyValue->PropertyId][] = ['Id' => $productPropertyValue->Id, 'Value' => $productPropertyValue->Value];
            $productPropertyValues_forSku[$productPropertyValue->Id] = ['PropertyId' => $productPropertyValue->PropertyId, 'Value' => $productPropertyValue->Value];
            $structure_propertyValue = new PropertyValue();
            $structure_propertyValue->Id = $productPropertyValue->Id;
            $structure_propertyValue->Value = $productPropertyValue->Value;
            $property_value_obj[$productPropertyValue->PropertyId][] = $structure_propertyValue;

        }
        foreach ($properties as $property) {
            $result['Properties'][] = ['Id' => $property->Id, 'Value' => $property->Name, 'PropertyValues' => $productPropertyValues_new[$property->Id]];
            $properties_forSku[$property->Id] = $property->Name;
            $structure_property = new Property();
            $structure_property->Id = $property->Id;
            $structure_property->Value = $property->Name;
            $structure_property->PropertyValues = $property_value_obj[$property->Id];
            $structure_product->Properties[] = $structure_property;
        }
        //Sku
        $productUnits = \App\Models\ProductUnit::find([
            'conditions' => 'ProductId=?0',
            'bind'       => [$product->Id],
        ]);
        $productUnitProductPropertyValues = \App\Models\ProductUnitProductPropertyValue::query()
            ->inWhere('ProductUnitId', array_column($productUnits->toArray(), 'Id'))
            ->execute();
        $productUnitProductPropertyValues_obj = [];
        foreach ($productUnitProductPropertyValues as $value) {
            /**
             * @var \App\Models\ProductUnitProductPropertyValue $value
             */
            $structure_sku_propertyId = new PropertyId();
            $structure_sku_propertyId->PropertyId = $productPropertyValues_forSku[$value->ProductPropertyValueId]['PropertyId'];
            $structure_sku_propertyId->PropertyName = $properties_forSku[$productPropertyValues_forSku[$value->ProductPropertyValueId]['PropertyId']];
            $structure_sku_propertyId->PropertyValueId = $value->ProductPropertyValueId;
            $structure_sku_propertyId->PropertyValueName = $productPropertyValues_forSku[$value->ProductPropertyValueId]['Value'];
            $productUnitProductPropertyValues_obj[$value->ProductUnitId][] = $structure_sku_propertyId;
        }
        $skuImages = \App\Models\ProductUnitPicture::query()
            ->inWhere('ProductUnitId', array_column($productUnits->toArray(), 'Id'))
            ->execute();
        $skuImages_obj = [];
        foreach ($skuImages as $image) {
            $structure_sku_image = new Image();
            $structure_sku_image->Id = $image->Id;
            $structure_sku_image->Value = $image->Image;
            $skuImages_obj[$image->ProductUnitId][] = $structure_sku_image;
        }
        foreach ($productUnits as $unit) {
            /**
             * @var \App\Models\ProductUnit $unit
             */
            $structure_sku = new Sku();
            $structure_sku->Id = $unit->Id;
            $structure_sku->Price = $unit->Price;
            $structure_sku->PriceForSlave = $unit->PriceForSlave;
            $structure_sku->Postage = $unit->Postage;
            $structure_sku->Number = $unit->Number;
            $structure_sku->IsDefault = $unit->IsDefault;
            $structure_sku->PropertyIds = $productUnitProductPropertyValues_obj[$unit->Id];
            $structure_sku->Images = $skuImages_obj[$unit->Id];
            $structure_product->Sku[] = $structure_sku;
        }
        //Attributes
        $Attributes = \App\Models\ProductAttribute::query()
            ->columns(['AttributeId as Id', 'Value'])
            ->leftJoin(\App\Models\Attribute::class, 'A.Id=AttributeId', 'A')
            ->where(sprintf('ProductId=%d', $product->Id))
            ->execute();
        foreach ($Attributes as $attribute) {
            $structure_attribute = new Attribute();
            $structure_attribute->Id = $attribute->Id;
            $structure_attribute->Value = $attribute->Value;
            $structure_product->Attributes[] = $structure_attribute;
        }
        return $structure_product;
    }

    /**
     * 生成新的mongo版本
     * @param int $productId
     * @return string
     */
    public function createMongo(int $productId): string
    {
        $product = self::product($productId);
        return $this->mongo->selectCollection(Mongo::collection_product)->insertOne($product)->getInsertedId()->__toString();
    }

    /**
     * 通过mongo主键得到mongo列表
     * @param array $mongoIds
     * @return array
     */
    public function productMongoList(array $mongoIds): array
    {
        foreach ($mongoIds as &$mongoId) {
            $mongoId = new \MongoDB\BSON\ObjectId($mongoId);
        }
        $datas = $this->mongo->selectCollection(Mongo::collection_product)->find(['_id' => ['$in' => $mongoIds]])->toArray() ?: [];
        $result = [];
        if ($datas) {
            foreach ($datas as $data) {
                $mapper = new \JsonMapper();
                $result[] = $mapper->map($data, new Product());
            }
        }
        return $result;
    }
}
