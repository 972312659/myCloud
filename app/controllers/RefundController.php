<?php

namespace App\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Libs\Order\Manager;
use App\Libs\Order\Refund;
use App\Libs\request\order\Create;
use App\Libs\request\order\Feedback;
use App\Models\Order;
use App\Models\OrderRefund;

class RefundController extends Controller
{
    public function createAction()
    {
        $auth = $this->session->get('auth');

        $mapper = new \JsonMapper();
        $data = new Create();
        $mapper->map($this->request->getJsonRawBody(), $data);

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'BuyerOrganizationId = ?0 AND Id = ?1',
            'bind'       => [$auth['OrganizationId'], $data->OrderId]
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        $manager = new Manager($this->db);

        if (!$manager->canRefund($order)) {
            throw new LogicException('订单不符合退款条件', Status::BadRequest);
        }

        /** @var OrderRefund $refund */
        $refund = OrderRefund::findFirst([
            'conditions' => 'OrderId = ?0',
            'bind'       => [$order->Id],
        ]);

        if ($refund) {
            throw new LogicException('您已申请过一次退款/退货操作', Status::BadRequest);
        }

        $this->db->begin();
        try {
            $manager->applyRefund($order, $data->Reason, $data->Type);
            $this->db->commit();
            $this->response->setJsonContent([
                'Message' => '申请成功'
            ]);
        } catch (\Exception $e) {
            $this->db->rollback();
            throw new $e;
        }
    }

    public function feedbackAction()
    {
        $auth = $this->session->get('auth');

        $mapper = new \JsonMapper();
        $data = new Feedback();
        $mapper->map($this->request->getJsonRawBody(), $data);

        $orderId = $data->OrderId;
        $feedback = $data->Feedback;
        $isRefund = $data->IsRefund;

        /** @var Order $order */
        $order = Order::findFirst([
            'conditions' => 'SellerOrganizationId = ?0 AND Id = ?1 AND Status = ?2',
            'bind'       => [$auth['OrganizationId'], $orderId, Order::STATUS_REFUNDING]
        ]);

        if (!$order) {
            throw new LogicException('未找到订单', Status::NotFound);
        }

        /** @var OrderRefund $orderRefund */
        $orderRefund = $order->Refund;

        if (!$orderRefund) {
            throw new LogicException('该订单没有有退款单', Status::NotFound);
        }

        try {
            $this->db->begin();
            $orderRefund->Feedback = $feedback;

            $manager = new Manager($this->db);
            $refund = new Refund($manager, $order);

            if ($isRefund) {
                $refund->agree();
            } else {
                $refund->refuse();
            }

            $this->response->setJsonContent([
                'Message' => '操作成功'
            ]);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
