<?php

namespace App\Controllers;

use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Libs\product\Mapper;
use App\Libs\product\structure\Product;
use App\Libs\ShoppingCart\Attribute;
use App\Libs\ShoppingCart\Item;
use App\Libs\ShoppingCart\Owner;
use App\Libs\ShoppingCart\ShoppingCart;
use App\Libs\ShoppingCart\Store\Mongo;
use App\Models\Organization;
use App\Models\ProductUnit;
use App\Models\ProductUnitStatus;
use MongoDB\Database;
use App\Models\Product as ProductModel;

class CartController extends Controller
{
    /**
     * @var ShoppingCart
     */
    protected $cart;

    /**
     * @var Mapper
     */
    protected $mapper;

    public function onConstruct()
    {
        /**
         * @var Database $db
         */
        $db = $this->getDI()->getShared('mongodb.database');
        $mongo = new Mongo($db);

        $auth = $this->session->get('auth');

        $this->cart = new ShoppingCart($mongo);
        $this->cart->restore(new Owner($auth['OrganizationId']));

        $this->mapper = new Mapper($db);
    }

    public function indexAction()
    {
        $items = $this->cart->getCollection()->getItems();
        /**
         * @var Organization $organization
         */
        $organization = Organization::findFirst([
            'conditions' => 'Id = ?0',
            'bind'       => [$this->cart->getOwner()->id],
        ]);

        $exception = new ParamException(400);

        $res = [];

        $caches = [];

        foreach ($items as $item) {
            if (empty($res[$item->provider_id])) {
                if (empty($caches['provider'][$item->provider_id])) {
                    $org = Organization::findFirst($item->provider_id);

                    if (!$org) {
                        $exception->loadFromModel($org);
                    }

                    $caches['provider'][$item->provider_id] = $org;
                }

                $provider = $caches['provider'][$item->provider_id];

                $res[$item->provider_id] = [
                    'id'       => $provider->Id,
                    'Name'     => $provider->Name,
                    'Products' => [],
                ];
            }

            if (empty($caches['product'][$item->product_id])) {
                $version = $this->mapper->mongoToProduct($item->product_id);

                //历史快照都没有的真的可以跳过了
                if (!$version) {
                    continue;
                }

                $caches['product'][$version->Id] = $version;
            }

            /**
             * @var Product $version
             */
            $version = $caches['product'][$item->product_id];

            $product_model = ProductModel::findFirst([
                'conditions' => 'Id = ?0',
                'bind'       => [$version->Id],
            ]);

            if (empty($product_model)) {
                continue;
            }

            /**
             * @var ProductUnit $sku
             */
            $sku = ProductUnit::findFirst([
                'conditions' => 'Id = ?0',
                'bind'       => [$item->sku_id],
            ]);

            //sku被删掉了就不返回了
            if (empty($sku)) {
                $this->cart->remove($item->sku_id);
                continue;
            }

            if (empty($sku->Status)) {
                $status = ProductUnitStatus::STATUS_OFF;
                $stock = 0;
            } else {
                $status = $sku->Status->Status;
                $stock = $sku->Status->Stock;
            }

            $images = $sku->Images->toArray();
            $res[$version->OrganizationId]['Products'][] = [
                'ProductId'    => $version->Id,
                'SkuId'        => $sku->Id,
                'Name'         => $version->Name,
                'Image'        => count($images) > 0 ? reset($images)['Image'] : '',
                'Price'        => $sku->getPriceByOrganization($organization),
                'Quantity'     => $item->quantity,
                'Postage'      => $sku->Postage,
                'Manufacturer' => $version->Manufacturer,
                'Stock'        => $stock,
                'Status'       => $status,
                'Attributes'   => array_map(function (Attribute $attribute) {
                    return [
                        'Name'  => $attribute->name,
                        'Value' => $attribute->value,
                    ];
                }, $item->attributes),
            ];
        }

        $res = array_filter($res, function ($v) {
            return !empty($v['Products']);
        });

        $this->cart->store();
        $this->response->setJsonContent(array_values($res));
    }

    public function updateAction()
    {
        $data = $this->request->getJsonRawBody(true);
        $this->cart->flush();
        $exception = new ParamException(Status::BadRequest);
        $products = [];

        foreach ($data as $value) {
            if (empty($products[$value['ProductId']])) {
                $products[$value['ProductId']] = $this->mapper->mongoToProduct($value['ProductId'], $value['SkuId']);
            }

            /**
             * @var Product $product
             */
            $product = $products[$value['ProductId']];

            if (!$product) {
                throw $exception;
            }

            $sku = $product->getSku($value['SkuId']);

            if (empty($sku)) {
                throw $exception;
            }

            $item = new Item();
            $item->sku_id = $value['SkuId'];
            $item->product_id = $value['ProductId'];
            $item->quantity = $value['Quantity'];
            $item->name = $product->Name;
            $item->provider_id = $product->OrganizationId;

            foreach ($sku->PropertyIds as $propertyId) {
                $item->attributes[] = new Attribute($propertyId->PropertyName, $propertyId->PropertyValueName);
            }

            $this->cart->put($item);
        }

        $this->cart->store();

        $this->indexAction();
    }

    public function addAction()
    {
        $data = $this->request->getJsonRawBody(true);

        $exception = new ParamException(Status::BadRequest);

        /**
         * @var Product $product
         */
        $product = $this->mapper->mongoToProduct($data['ProductId'], $data['SkuId']);

        if (!$product) {
            throw $exception;
        }

        $sku = $product->getSku($data['SkuId']);

        if (empty($sku)) {
            throw $exception;
        }

        $item = new Item();
        $item->sku_id = $data['SkuId'];
        $item->product_id = $data['ProductId'];
        $item->quantity = $data['Quantity'];
        $item->name = $product->Name;
        $item->provider_id = $product->OrganizationId;
        foreach ($sku->PropertyIds as $propertyId) {
            $item->attributes[] = new Attribute($propertyId->PropertyName, $propertyId->PropertyValueName);
        }

        if (empty($data['Increment'])) {
            $this->cart->put($item);
        } else {
            //叠加数量
            $this->cart->increment($item, $data['Quantity']);
        }

        $this->cart->store();

        $this->indexAction();
    }

    public function delAction(int $sku_id)
    {
        $this->cart->remove($sku_id);

        $this->cart->store();

        $this->indexAction();
    }
}
