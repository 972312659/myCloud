<?php

namespace App\Controllers;

use App\Enums\Mongo;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\ExpressHundred;
use App\Libs\Order\Receive;
use App\Libs\Order\Verify;
use App\Libs\Order\Manager;
use App\Libs\Pagination;
use App\Libs\product\Mapper;
use App\Libs\product\structure\PropertyId;
use App\Libs\request\order\BuyerReceive;
use App\Libs\request\order\Pay;
use App\Libs\request\order\UpdateExpress;
use App\Libs\ShoppingCart\Owner;
use App\Libs\ShoppingCart\ShoppingCart;
use App\Libs\Sphinx;
use App\Libs\sphinx\TableName as SphinxTableName;
use App\Models\Address;
use App\Models\ExpressCompany;
use App\Models\InteriorTrade;
use App\Models\InteriorTradeAndOrder;
use App\Models\Order;
use App\Models\OrderAndProductUnit;
use App\Models\OrderExpress;
use App\Models\OrderInfo;
use App\Models\OrderLog;
use App\Models\OrderRefund;
use App\Models\Organization;
use App\Models\ProductUnitStatus;
use Phalcon\Db\RawValue;
use App\Libs\Order\Pay as OrderPay;

class OrderController extends Controller
{
    protected $mongoDb;
    /** @var  Mapper */
    protected $mapper;

    public function onConstruct()
    {
        $this->mongoDb = $this->getDI()->getShared(Mongo::database);
        $this->mapper = new Mapper($this->mongoDb);
    }

    /**
     * 订单确认
     */
    public function verifyAction()
    {
        try {
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }

            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }

            $datas = $this->request->getJsonRawBody(true);
            if (!count($datas)) {
                throw new LogicException('未选择任何物品', Status::BadRequest);
            }

            $sku = [];
            $productIds = [];
            foreach ($datas as $data) {
                $productIds[] = $data['ProductId'];
                $sku[] = ['SkuId' => $data['SkuId'], 'SkuQuantity' => $data['Quantity']];
            }

            $productSphinx = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
            $productSphinxResult = $productSphinx->getList($productIds);

            $mongoIds = [];
            if ($productSphinxResult) {
                $mongoIds = array_column($productSphinxResult, 'mongoid');
            }

            $products = $this->mapper->productMongoList($mongoIds);

            $orderCount = new Verify($sku, $productSphinxResult, $auth['HospitalId'] == $auth['OrganizationId']);
            $result = $orderCount->build($products);

            $this->response->setJsonContent($result);
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function cancelAction(int $id)
    {
        if (!$this->request->isGet()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException('请登录', Status::Unauthorized);
        }

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND BuyerOrganizationId = ?1',
            'bind'       => [$id, $auth['OrganizationId']],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::BadRequest);
        }
        $manager = new Manager($this->db);

        if (!$manager->canCancel($order)) {
            throw new LogicException('订单不可取消', Status::BadRequest);
        }

        try {
            $this->db->begin();

            $manager->cancel($order, '用户取消');

            $this->db->commit();

            $this->response->setJsonContent([
                'Message' => '订单取消成功',
            ]);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function detailAction(int $id)
    {
        if (!$this->request->isGet()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException('请登录', Status::Unauthorized);
        }

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND (BuyerOrganizationId = ?1 OR SellerOrganizationId = ?2)',
            'bind'       => [$id, $auth['OrganizationId'], $auth['OrganizationId']],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::BadRequest);
        }

        $products = [];

        foreach ($order->Items as $item) {
            /** @var \App\Libs\product\structure\Product $product */
            $product = $this->mapper->mongoIdToProduct($item->ProductVersion);
            $sku = $product->getSku($item->ProductUnitId);

            $products[] = [
                'ProductId'  => $product->Id,
                'SkuId'      => $sku->Id,
                'Name'       => $product->Name,
                'Price'      => $item->Price,
                'Quantity'   => $item->Quantity,
                'Image'      => count($sku->Images) > 0 ? reset($sku->Images)->Value : '',
                'Attributes' => array_map(function (PropertyId $property) {
                    return [
                        'Name'  => $property->PropertyName,
                        'Value' => $property->PropertyValueName,
                    ];
                }, $sku->PropertyIds),
            ];
        }

        /** @var OrderInfo $info */
        $info = $order->Info;

        $this->response->setJsonContent([
            'Id'           => $order->Id,
            'SerialNumber' => $order->OrderNumber,
            'Amounts'      => $order->Amount,
            'RealAmount'   => $order->RealAmount,
            'Postage'      => $order->Postage,
            'Status'       => $order->Status,
            'Created'      => $order->Created,
            'Address'      => join('', [$info->Province->Name, $info->City->Name, $info->Area->Name, $info->Address]),
            'Phone'        => $info->Phone,
            'Contacts'     => $info->Contacts,
            'CloseCause'   => $order->CloseCause,
            'Provider'     => [
                'Id'   => $order->SellerOrganizationId,
                'Name' => $order->SellerOrganizationName,
            ],
            'Products'     => $products,
            'IsRefund'     => (bool)$order->IsRefund,
        ]);
    }


    public function createForHospitalAction()
    {
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }

            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            if ($auth['IsMain'] === Organization::ISMAIN_SLAVE) {
                throw new LogicException('用户身份错误', Status::BadRequest);
            }
            $datas = $this->request->getJsonRawBody(true);
            if (!count($datas)) {
                throw new LogicException('未选择任何物品', Status::BadRequest);
            }

            /** @var Address $address */
            $address = Address::findFirst(['conditions' => 'Id=?0 and OrganizationId=?1', 'bind' => [$datas['AddressId'], $auth['OrganizationId']]]);
            if (!$address) {
                throw new LogicException('收货地址错误', Status::BadRequest);
            }

            $sku = [];
            $productIds = [];
            foreach ($datas['Products'] as $item) {
                if (!$this->mapper->mongoIdToProduct($item['Version'])) {
                    throw new LogicException('商品错误', Status::BadRequest);
                }
                $mogoid = $this->mapper->getMongoId($item['ProductId']);
                if ($item['Version'] !== $mogoid) {
                    throw new LogicException('商品发生变化，请重新确认订单', Status::BadRequest);
                }
                $productIds[] = $item['ProductId'];
                $sku[] = ['SkuId' => $item['SkuId'], 'SkuQuantity' => $item['Quantity']];
            }

            $remarks = [];
            foreach ($datas['Remarks'] as $remark) {
                $remarks[$remark['ProvierId']] = $remark['Remark'];
            }

            $productSphinx = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
            $productSphinxResult = $productSphinx->getList($productIds);

            $orderVerify = new Verify($sku, $productSphinxResult, true);
            $result = $orderVerify->build($products = $this->mapper->productMongoList(array_column($productSphinxResult, 'mongoid')));
            $exception = new ParamException(Status::BadRequest);

            $amount = $orderVerify->amount();
            //总金额
            $totalAmount = $amount['totalAmount'];
            //平台手续费
            $cloudAmount = $amount['cloudAmount'];
            //实付金额
            $realAmount = $amount['realAmount'];
            //邮费
            $postage = $amount['postage'];

            //订单信息
            $orderInfo = new OrderInfo();
            $orderInfo->ProvinceId = $address->ProvinceId;
            $orderInfo->CityId = $address->CityId;
            $orderInfo->AreaId = $address->AreaId;
            $orderInfo->Address = $address->Address;
            $orderInfo->Phone = $address->Phone;
            $orderInfo->Contacts = $address->Contacts;
            if (!$orderInfo->save()) {
                $exception->loadFromModel($orderInfo);
                throw $exception;
            }
            $orderInfo->refresh();

            //创建父订单
            /*$hasChild = count($result['Providers']) > 1;
            $parentOrder = new Order();
            $parentOrder->SellerOrganizationId = $hasChild ? null : $result['Providers'][0]['Id'];
            $parentOrder->SellerOrganizationName = $hasChild ? null : $result['Providers'][0]['Name'];
            $parentOrder->BuyerOrganizationId = $auth['OrganizationId'];
            $parentOrder->BuyerOrganizationName = $auth['OrganizationName'];
            $parentOrder->Quantity = $result['Quantity'];
            $parentOrder->Amount = $totalAmount;
            $parentOrder->RealAmount = $realAmount;
            $parentOrder->Postage = $postage;
            $parentOrder->Remark = $hasChild ? '' : $remarks[$result['Providers'][0]['Id']];
            $parentOrder->ParentId = Order::PARENT_ID_TOP;
            $parentOrder->IsParent = $hasChild ? Order::IsParent_parent : Order::IsParent_both;
            $parentOrder->OrderInfoId = $orderInfo->Id;
            if (!$parentOrder->save()) {
                $exception->loadFromModel($parentOrder);
                throw $exception;
            }
            $parentOrder->refresh();*/

            //低于库存警戒值的提醒
            $warning = [];
            $mongo = new \App\Libs\ShoppingCart\Store\Mongo($this->mongoDb);
            $cart = new ShoppingCart($mongo);
            $cart->restore(new Owner($auth['OrganizationId']));
            //创建子订单
            foreach ($result['Providers'] as $provider) {
                //订单
                /*if ($hasChild) {
                    $order = new Order();
                    $order->SellerOrganizationId = $provider['Id'];
                    $order->SellerOrganizationName = $provider['Name'];
                    $order->BuyerOrganizationId = $auth['OrganizationId'];
                    $order->BuyerOrganizationName = $auth['OrganizationName'];
                    $order->Quantity = $provider['Quantity'];
                    $order->Amount = $provider['Total'];
                    $order->Postage = $provider['Postage'];
                    $order->RealAmount = $provider['Total'];
                    $order->Remark = $remarks[$provider['Id']] ?: '';
                    $order->ParentId = $parentOrder->Id;
                    $order->IsParent = Order::IsParent_child;
                    $order->OrderInfoId = $orderInfo->Id;
                    $order->Status = Order::STATUS_WAIT_PAY;
                    if (!$order->save()) {
                        $exception->loadFromModel($order);
                        throw $exception;
                    }
                    $order->refresh();
                }*/
                $order = new Order();
                $order->SellerOrganizationId = $provider['Id'];
                $order->SellerOrganizationName = $provider['Name'];
                $order->BuyerOrganizationId = $auth['OrganizationId'];
                $order->BuyerOrganizationName = $auth['OrganizationName'];
                $order->Quantity = $provider['Quantity'];
                $order->Amount = $provider['Total'];
                $order->Postage = $provider['Postage'];
                $order->RealAmount = $provider['Total'];
                $order->Remark = $remarks[$provider['Id']] ?: '';
                $order->ParentId = Order::PARENT_ID_TOP;
                $order->IsParent = Order::IsParent_both;
                $order->OrderInfoId = $orderInfo->Id;
                $order->Status = Order::STATUS_WAIT_PAY;
                if (!$order->save()) {
                    $exception->loadFromModel($order);
                    throw $exception;
                }
                $order->refresh();

                //订单商品关联
                foreach ($provider['Products'] as $product) {
                    $orderAndProductUnit = new OrderAndProductUnit();
                    $orderAndProductUnit->OrderId = $order->Id;
                    // $orderAndProductUnit->ChildOrderId = $hasChild ? $order->Id : $parentOrder->Id;
                    $orderAndProductUnit->ChildOrderId = $order->Id;
                    $orderAndProductUnit->ProductUnitId = $product['SkuId'];
                    $orderAndProductUnit->Quantity = $product['Quantity'];
                    $orderAndProductUnit->Price = $product['Price'];
                    $orderAndProductUnit->ProductVersion = $product['Version'];
                    if (!$orderAndProductUnit->save()) {
                        $exception->loadFromModel($orderAndProductUnit);
                        throw $exception;
                    }

                    //减少库存数量
                    /**
                     * @var ProductUnitStatus $productUnitStatus
                     */
                    $productUnitStatus = ProductUnitStatus::findFirst(sprintf('ProductUnitId=%d', $product['SkuId']));
                    if ($productUnitStatus->Stock < $product['Quantity']) {
                        throw new LogicException($product['Name'] . '库存不足', Status::BadRequest);
                    }
                    $productUnitStatus->Stock = new RawValue(sprintf('Stock-%d', $product['Quantity']));
                    if ($productUnitStatus->update() === false) {
                        $exception->loadFromModel($productUnitStatus);
                        throw $exception;
                    }
                    $productUnitStatus->refresh();

                    //删除购物车
                    $cart->remove($productUnitStatus->ProductUnitId);

                    //低于库存警戒值的提醒
                    if ($productUnitStatus->Stock <= $productUnitStatus->WarningLine) {
                        $warning[] = ['OrganizationId' => $provider['Id'], 'ProductName' => $product['Name'], 'Stock' => $productUnitStatus->Stock];
                    }
                }

                //订单内部审核单
                $interiorTrade = new InteriorTrade();
                $interiorTrade->SendOrganizationId = $auth['OrganizationId'];
                $interiorTrade->AcceptOrganizationId = null;
                $interiorTrade->SendOrganizationName = $auth['OrganizationName'];
                $interiorTrade->AcceptOrganizationName = null;
                $interiorTrade->Amount = $order->RealAmount;
                $interiorTrade->Message = '采购商品';
                $interiorTrade->Style = InteriorTrade::STYLE_PRODUCT;
                $interiorTrade->Status = InteriorTrade::STATUS_WAIT;
                $interiorTrade->Created = time();
                $interiorTrade->ShareCloud = 0;
                $interiorTrade->Total = $order->RealAmount;
                if (!$interiorTrade->save()) {
                    $exception->loadFromModel($interiorTrade);
                    throw $exception;
                }
                $interiorTrade->refresh();

                //订单内部审核单关联
                $interiorTradeAndOrder = new InteriorTradeAndOrder();
                $interiorTradeAndOrder->InteriorTradeId = $interiorTrade->Id;
                $interiorTradeAndOrder->OrderId = $order->Id;
                $interiorTradeAndOrder->Amount = $order->RealAmount;
                $interiorTradeAndOrder->ShareCloud = 0;
                if (!$interiorTradeAndOrder->save()) {
                    $exception->loadFromModel($interiorTradeAndOrder);
                    throw $exception;
                }
            }

            /*//订单内部审核单
            $interiorTrade = new InteriorTrade();
            $interiorTrade->SendOrganizationId = $auth['OrganizationId'];
            $interiorTrade->AcceptOrganizationId = null;
            $interiorTrade->SendOrganizationName = $auth['OrganizationName'];
            $interiorTrade->AcceptOrganizationName = null;
            $interiorTrade->Amount = $realAmount;
            $interiorTrade->Message = '采购商品';
            $interiorTrade->Style = InteriorTrade::STYLE_PRODUCT;
            $interiorTrade->Status = InteriorTrade::STATUS_WAIT;
            $interiorTrade->Created = time();
            $interiorTrade->ShareCloud = $cloudAmount;
            $interiorTrade->Total = $realAmount;
            if (!$interiorTrade->save()) {
                $exception->loadFromModel($interiorTrade);
                throw $exception;
            }
            $interiorTrade->refresh();

            //订单内部审核单关联
            $interiorTradeAndOrder = new InteriorTradeAndOrder();
            $interiorTradeAndOrder->InteriorTradeId = $interiorTrade->Id;
            $interiorTradeAndOrder->OrderId = $parentOrder->Id;
            $interiorTradeAndOrder->Amount = $parentOrder->RealAmount;
            $interiorTradeAndOrder->ShareCloud = $cloudAmount;
            if (!$interiorTradeAndOrder->save()) {
                $exception->loadFromModel($interiorTradeAndOrder);
                throw $exception;
            }*/

            $this->db->commit();

            //删除购物车
            $cart->store();

            //低于库存警戒值提醒
            if ($warning) {
                $orderVerify->productStockWarning($warning);
            }

            // $this->response->setJsonContent(['Id' => $parentOrder->Id]);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function createForSlaveAction()
    {
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }

            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            if ($auth['IsMain'] !== Organization::ISMAIN_SLAVE) {
                throw new LogicException('用户身份错误', Status::BadRequest);
            }
            $datas = $this->request->getJsonRawBody(true);
            if (!count($datas)) {
                throw new LogicException('未选择任何物品', Status::BadRequest);
            }

            /** @var Address $address */
            $address = Address::findFirst(['conditions' => 'Id=?0 and OrganizationId=?1', 'bind' => [$datas['AddressId'], $auth['OrganizationId']]]);
            if (!$address) {
                throw new LogicException('收货地址错误', Status::BadRequest);
            }

            $sku = [];
            $productIds = [];
            foreach ($datas['Products'] as $item) {
                if (!$this->mapper->mongoIdToProduct($item['Version'])) {
                    throw new LogicException('商品错误', Status::BadRequest);
                }
                $mogoid = $this->mapper->getMongoId($item['ProductId']);
                if ($item['Version'] !== $mogoid) {
                    throw new LogicException('商品发生变化，请重新确认订单', Status::BadRequest);
                }
                $productIds[] = $item['ProductId'];
                $sku[] = ['SkuId' => $item['SkuId'], 'SkuQuantity' => $item['Quantity']];
            }

            $remarks = [];
            foreach ($datas['Remarks'] as $remark) {
                $remarks[$remark['ProvierId']] = $remark['Remark'];
            }

            $productSphinx = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
            $productSphinxResult = $productSphinx->getList($productIds);

            $orderVerify = new Verify($sku, $productSphinxResult, false);
            $result = $orderVerify->build($products = $this->mapper->productMongoList(array_column($productSphinxResult, 'mongoid')));
            $exception = new ParamException(Status::BadRequest);

            $amount = $orderVerify->amount();
            //总金额
            $totalAmount = $amount['totalAmount'];
            //平台手续费
            $cloudAmount = $amount['cloudAmount'];
            //实付金额
            $realAmount = $amount['realAmount'];
            //邮费
            $postage = $amount['postage'];

            //订单信息
            $orderInfo = new OrderInfo();
            $orderInfo->ProvinceId = $address->ProvinceId;
            $orderInfo->CityId = $address->CityId;
            $orderInfo->AreaId = $address->AreaId;
            $orderInfo->Address = $address->Address;
            $orderInfo->Phone = $address->Phone;
            $orderInfo->Contacts = $address->Contacts;
            if (!$orderInfo->save()) {
                $exception->loadFromModel($orderInfo);
                throw $exception;
            }
            $orderInfo->refresh();

            //创建父订单
            /*$hasChild = count($result['Providers']) > 1;
            $parentOrder = new Order();
            $parentOrder->SellerOrganizationId = $hasChild ? null : $result['Providers'][0]['Id'];
            $parentOrder->SellerOrganizationName = $hasChild ? null : $result['Providers'][0]['Name'];
            $parentOrder->BuyerOrganizationId = $auth['OrganizationId'];
            $parentOrder->BuyerOrganizationName = $auth['OrganizationName'];
            $parentOrder->Quantity = $result['Quantity'];
            $parentOrder->Amount = $totalAmount;
            $parentOrder->RealAmount = $realAmount;
            $parentOrder->Postage = $postage;
            $parentOrder->Remark = $hasChild ? '' : $remarks[$result['Providers'][0]['Id']];
            $parentOrder->ParentId = Order::PARENT_ID_TOP;
            $parentOrder->IsParent = $hasChild ? Order::IsParent_parent : Order::IsParent_both;
            $parentOrder->OrderInfoId = $orderInfo->Id;
            if (!$parentOrder->save()) {
                $exception->loadFromModel($parentOrder);
                throw $exception;
            }
            $parentOrder->refresh();*/

            //低于库存警戒值的提醒
            $warning = [];
            $mongo = new \App\Libs\ShoppingCart\Store\Mongo($this->mongoDb);
            $cart = new ShoppingCart($mongo);
            $cart->restore(new Owner($auth['OrganizationId']));
            $orderIds = [];
            //创建子订单
            foreach ($result['Providers'] as $provider) {
                //订单
                /*if ($hasChild) {
                    $order = new Order();
                    $order->SellerOrganizationId = $provider['Id'];
                    $order->SellerOrganizationName = $provider['Name'];
                    $order->BuyerOrganizationId = $auth['OrganizationId'];
                    $order->BuyerOrganizationName = $auth['OrganizationName'];
                    $order->Quantity = $provider['Quantity'];
                    $order->Amount = $provider['Total'];
                    $order->RealAmount = $provider['Total'];
                    $order->Postage = $provider['Postage'];
                    $order->Remark = $remarks[$provider['Id']] ?: '';
                    $order->ParentId = $parentOrder->Id;
                    $order->IsParent = Order::IsParent_child;
                    $order->OrderInfoId = $orderInfo->Id;
                    if (!$order->save()) {
                        $exception->loadFromModel($order);
                        throw $exception;
                    }
                    $order->refresh();
                }*/
                $order = new Order();
                $order->SellerOrganizationId = $provider['Id'];
                $order->SellerOrganizationName = $provider['Name'];
                $order->BuyerOrganizationId = $auth['OrganizationId'];
                $order->BuyerOrganizationName = $auth['OrganizationName'];
                $order->Quantity = $provider['Quantity'];
                $order->Amount = $provider['Total'];
                $order->RealAmount = $provider['Total'];
                $order->Postage = $provider['Postage'];
                $order->Remark = $remarks[$provider['Id']] ?: '';
                $order->ParentId = Order::PARENT_ID_TOP;
                $order->IsParent = Order::IsParent_both;
                $order->OrderInfoId = $orderInfo->Id;
                if (!$order->save()) {
                    $exception->loadFromModel($order);
                    throw $exception;
                }
                $order->refresh();
                $orderIds[] = $order->Id;
                //订单商品关联
                foreach ($provider['Products'] as $product) {
                    $orderAndProductUnit = new OrderAndProductUnit();
                    $orderAndProductUnit->OrderId = $order->Id;
                    // $orderAndProductUnit->ChildOrderId = $hasChild ? $order->Id : $parentOrder->Id;
                    $orderAndProductUnit->ChildOrderId = $order->Id;
                    $orderAndProductUnit->ProductUnitId = $product['SkuId'];
                    $orderAndProductUnit->Quantity = $product['Quantity'];
                    $orderAndProductUnit->Price = $product['Price'];
                    $orderAndProductUnit->ProductVersion = $product['Version'];
                    if (!$orderAndProductUnit->save()) {
                        $exception->loadFromModel($orderAndProductUnit);
                        throw $exception;
                    }
                    //减少库存数量
                    /**
                     * @var ProductUnitStatus $productUnitStatus
                     */
                    $productUnitStatus = ProductUnitStatus::findFirst(sprintf('ProductUnitId=%d', $product['SkuId']));
                    if ($productUnitStatus->Stock < $product['Quantity']) {
                        throw new LogicException($product['Name'] . '库存不足', Status::BadRequest);
                    }
                    $productUnitStatus->Stock = new RawValue(sprintf('Stock-%d', $product['Quantity']));
                    if ($productUnitStatus->update() === false) {
                        $exception->loadFromModel($productUnitStatus);
                        throw $exception;
                    }
                    $productUnitStatus->refresh();

                    //删除购物车
                    $cart->remove($productUnitStatus->ProductUnitId);

                    //低于库存警戒值的提醒
                    if ($productUnitStatus->Stock <= $productUnitStatus->WarningLine) {
                        $warning[] = ['OrganizationId' => $provider['Id'], 'ProductName' => $product['Name'], 'Stock' => $productUnitStatus->Stock];
                    }
                }
            }
            $this->db->commit();

            //删除购物车
            $cart->store();

            //低于库存警戒值提醒
            if ($warning) {
                $orderVerify->productStockWarning($warning);
            }

            $this->response->setJsonContent(['OrderIds' => $orderIds, 'Amount' => $realAmount]);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function buyerAction()
    {
        if (!$this->request->isGet()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');
        $this->response->setJsonContent($this->orders('BuyerOrganizationId = :bid:', ['bid' => $auth['OrganizationId']]));
    }

    public function sellerAction()
    {
        if (!$this->request->isGet()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');
        $this->response->setJsonContent($this->orders('SellerOrganizationId = :bid:', ['bid' => $auth['OrganizationId']]));
    }

    public function orders($condition = '', $bind = [])
    {
        $pagination = new Pagination($this->request);

        $criteria = Order::query()
            ->Where($condition, $bind)
            ->orderBy('Id DESC');

        $status = $this->request->getQuery('Status', 'absint');
        if ($status) {
            $criteria->andWhere('Status = :status:', ['status' => $status]);
        }

        $number = $this->request->getQuery('Number');
        if (!empty($number)) {
            $criteria->andWhere('OrderNumber = :number:', ['number' => $number]);
        }

        $start = $this->request->getQuery('Start', 'absint');
        $end = $this->request->getQuery('End', 'absint');
        if (!empty($start) && !empty($end)) {
            $criteria->betweenWhere(
                'Created',
                $start,
                $end
            );
        }

        $paginate = $pagination->find($criteria->createBuilder());

        $data = [];
        foreach ($paginate['Data'] as $order) {
            $products = [];

            foreach ($order->Items as $item) {
                /** @var \App\Libs\product\structure\Product $product */
                $product = $this->mapper->mongoIdToProduct($item->ProductVersion);
                $sku = $product->getSku($item->ProductUnitId);

                $products[] = [
                    'ProductId'  => $product->Id,
                    'SkuId'      => $sku->Id,
                    'Name'       => $product->Name,
                    'Price'      => $item->Price,
                    'Quantity'   => $item->Quantity,
                    'Image'      => count($sku->Images) > 0 ? reset($sku->Images)->Value : '',
                    'Attributes' => array_map(function (PropertyId $property) {
                        return [
                            'Name'  => $property->PropertyName,
                            'Value' => $property->PropertyValueName,
                        ];
                    }, $sku->PropertyIds),
                ];
            }

            $data[] = [
                'Id'           => $order->Id,
                'SerialNumber' => $order->OrderNumber,
                'Amounts'      => $order->Amount,
                'RealAmount'   => $order->RealAmount,
                'Postage'      => $order->Postage,
                'Status'       => $order->Status,
                'Created'      => $order->Created,
                'Provider'     => [
                    'Id'   => $order->SellerOrganizationId,
                    'Name' => $order->SellerOrganizationName,
                ],
                'CloseCause'   => $order->CloseCause,
                'Products'     => $products,
                'IsRefund'     => (bool)$order->IsRefund,
            ];
        }

        $paginate['Data'] = $data;

        return $paginate;
    }

    public function sellerUpdateExpressAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');

        $data = new UpdateExpress();
        (new \JsonMapper())->map($this->request->getJsonRawBody(), $data);
        $id = $data->OrderId;

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND SellerOrganizationId = ?1 AND Status = ?2',
            'bind'       => [
                $id,
                $auth['OrganizationId'],
                Order::STATUS_WAIT_SEND,
            ],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        $express = OrderExpress::findFirst([
            'conditions' => 'OrderId = ?0',
            'bind'       => $id,
            'Type'       => OrderExpress::TYPE_SELLER,
        ]);

        if (!$express) {
            $express = new OrderExpress();
            $express->OrderId = $id;
            $express->Type = OrderExpress::TYPE_SELLER;
        }

        try {
            $this->db->begin();
            $manager = new Manager($this->db);
            $log = new OrderLog();
            $manager->changeStatus($order, Order::STATUS_WAIT_RECEIVE, $log);
            $log->save();
            $order->save();

            $express->Com = $data->Com;
            $express->Number = $data->Number;

            if (!$express->save()) {
                throw (new LogicException('快递信息更新失败', Status::BadRequest));
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->response->setJsonContent([
            'Message' => '更新成功',
        ]);
    }

    public function buyerUpdateExpressAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');

        $data = new UpdateExpress();
        (new \JsonMapper())->map($this->request->getJsonRawBody(), $data);
        $id = $data->OrderId;

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND BuyerOrganizationId = ?1 AND Status = ?2',
            'bind'       => [
                $id,
                $auth['OrganizationId'],
                Order::STATUS_REFUNDING,
            ],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        /** @var OrderRefund $refund */
        $refund = $order->Refund;

        if ($refund->Type != OrderRefund::TYPE_RETURN_GOODS) {
            throw new LogicException('退款类型错误', Status::BadRequest);
        }

        $express = OrderExpress::findFirst([
            'conditions' => 'OrderId = ?0',
            'bind'       => $id,
            'Type'       => OrderExpress::TYPE_BUYER,
        ]);

        if (!$express) {
            $express = new OrderExpress();
            $express->OrderId = $id;
            $express->Type = OrderExpress::TYPE_BUYER;
        }

        $express->Com = $data->Com;
        $express->Number = $data->Number;

        if (!$express->save()) {
            throw (new LogicException('快递信息更新失败', Status::BadRequest));
        }

        $refund->Status = OrderRefund::STATUS_WAIT_RECEIVE;
        $refund->save();

        $this->response->setJsonContent([
            'Message' => '更新成功',
        ]);
    }

    public function expressAction($id)
    {
        if (!$this->request->isGet()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException('请登录', Status::Unauthorized);
        }

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND (BuyerOrganizationId = ?1 OR SellerOrganizationId = ?2)',
            'bind'       => [$id, $auth['OrganizationId'], $auth['OrganizationId']],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        /** @var OrderExpress $express */
        $express = $order->Express;

        /** @var ExpressCompany $company */
        $company = ExpressCompany::findFirst([
            'conditions' => 'Com = ?0',
            'bind'       => [$express->Com],
        ]);

        if (!$company) {
            throw new LogicException('未找到对应的快递公司', Status::BadRequest);
        }

        $kuaidi100 = new ExpressHundred();
        $data = $kuaidi100->get($express->Com, $express->Number);

        $res = [
            'Com'    => $company->Com,
            'Name'   => $company->Name,
            'Number' => $express->Number,
            'Data'   => [],
        ];

        if ($data['status']) {
            $res['Data'] = array_map(function ($v) use ($company) {
                return [
                    'Time'    => $v['time'],
                    'Context' => $v['context'],
                ];
            }, $data['message']);
        }

        $this->response->setJsonContent($res);
    }

    public function payAction()
    {
        if (!$this->request->isPost()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }

        $auth = $this->session->get('auth');

        $data = new Pay();
        (new \JsonMapper())->map($this->request->getJsonRawBody(), $data);

        $org = Organization::findFirst([
            'conditions' => 'Id = ?0 AND IsMain = ?1',
            'bind'       => [$auth['OrganizationId'], Organization::ISMAIN_SLAVE],
        ]);

        if (!$org) {
            throw new LogicException('非法支付请求', Status::BadRequest);
        }

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND Status = ?1 AND BuyerOrganizationId = ?2',
            'bind'       => [$data->OrderId, Order::STATUS_WAIT_PAY, $auth['OrganizationId']],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }
        $manager = new Manager($this->db);
        $manager->begin();
        try {
            $pay = new OrderPay($manager);
            $pay->slavePay($order);
            $manager->commit();

            $this->response->setJsonContent(['Message' => '支付成功']);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->response->setJsonContent(['Message' => '支付失败']);
            $manager->rollback();

            throw $e;
        }
    }

    public function buyerReceiveAction()
    {
        $auth = $this->session->get('auth');

        $data = new BuyerReceive();
        (new \JsonMapper())->map($this->request->getJsonRawBody(), $data);

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND BuyerOrganizationId = ?1 AND Status = ?2',
            'bind'       => [$data->OrderId, $auth['OrganizationId'], Order::STATUS_WAIT_RECEIVE],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        try {
            $receive = new Receive($this->di->get('order.manager'));

            $receive->buyerReceive($order);
            $this->response->setJsonContent([
                'Message' => '操作成功',
            ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function sellerReceiveAction()
    {
        $auth = $this->session->get('auth');

        $data = new BuyerReceive();
        (new \JsonMapper())->map($this->request->getJsonRawBody(), $data);

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND SellerOrganizationId = ?1 AND Status = ?2',
            'bind'       => [$data->OrderId, $auth['OrganizationId'], Order::STATUS_REFUNDING],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        try {
            $receive = new Receive($this->di->get('order.manager'));

            $receive->sellerReceive($order);
            $this->response->setJsonContent([
                'Message' => '操作成功',
            ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function refundAction(int $id)
    {
        $auth = $this->session->get('auth');

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'Id = ?0 AND (BuyerOrganizationId = ?1 OR SellerOrganizationId = ?2)',
            'bind'       => [$id, $auth['OrganizationId'], $auth['OrganizationId']],
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        /** @var OrderRefund $refund */
        $refund = $order->Refund;

        if (!$refund) {
            throw new LogicException('未找到退款单', Status::NotFound);
        }

        $this->response->setJsonContent([
            'Id'           => $refund->Id,
            'SerialNumber' => $refund->SerialNumber,
            'OrderId'      => $refund->OrderId,
            'Reason'       => $refund->Reason,
            'Feedback'     => $refund->Feedback,
            'Status'       => $refund->Status,
            'Created'      => $refund->Created,
            'Type'         => $refund->Type,
            'Amount'       => $refund->Amount,
        ]);
    }
}
