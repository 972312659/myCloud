<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/26
 * Time: 上午11:41
 */

namespace App\Libs\interiorTrade;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\InteriorTrade;
use App\Models\InteriorTradeAndOrder;
use App\Models\Order;
use App\Models\OrderAndProductUnit;
use App\Models\ProductUnitStatus;
use Phalcon\Db\RawValue;

class UnPass
{
    protected $interiorTrade;

    public function __construct(InteriorTrade $interiorTrade)
    {
        $this->interiorTrade = $interiorTrade;
    }

    public function product()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var InteriorTradeAndOrder $interiorTradeAndOrder */
            $interiorTradeAndOrder = InteriorTradeAndOrder::findFirst(['conditions' => 'InteriorTradeId=?0', 'bind' => [$this->interiorTrade->Id]]);
            /** @var Order $order */
            $order = Order::findFirst(sprintf('Id=%d', $interiorTradeAndOrder->OrderId));
            $order->Status = Order::STATUS_CLOSED;
            $order->CloseCause = $this->interiorTrade->Explain;
            if (!$order->save()) {
                $exception->loadFromModel($order);
                throw $exception;
            }
            if ($order->IsParent == Order::IsParent_parent) {
                $orders = Order::find([
                    'conditions' => 'ParentId=?0',
                    'bind'       => [$order->Id],
                ]);
                if (count($orders->toArray())) {
                    foreach ($orders as $item) {
                        /** @var Order $item */
                        $item->Status = Order::STATUS_CLOSED;
                        $item->CloseCause = $this->interiorTrade->Explain;
                        if (!$item->save()) {
                            $exception->loadFromModel($item);
                            throw $exception;
                        }
                    }
                    //将库存加上
                    $orderAndProductUnits = OrderAndProductUnit::find([
                        'conditions' => 'OrderId=?0',
                        'bind'       => [$order->Id],
                    ]);
                    foreach ($orderAndProductUnits as $productUnit) {
                        /** @var OrderAndProductUnit $productUnit */
                        $sku = ProductUnitStatus::findFirst(sprintf('ProductUnitId=%d', $productUnit->ProductUnitId));
                        /** @var ProductUnitStatus $sku */
                        if ($sku) {
                            $sku->Stock = new RawValue(sprintf('Stock+%d', $productUnit->Quantity));
                            if (!$sku->save()) {
                                $exception->loadFromModel($sku);
                                throw $exception;
                            }
                        }
                    }
                }

            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}