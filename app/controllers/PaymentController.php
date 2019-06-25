<?php

namespace App\Controllers;

use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\AlipayOpen;
use App\Libs\Push;
use App\Models\Bill;
use App\Models\Event;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\Trade;
use App\Models\TradeLog;
use App\Models\UserEvent;
use Phalcon\Db\RawValue;
use App\Libs\fake\models\Trade as TradeFake;
use App\Libs\fake\models\Organization as OrganizationFake;

class PaymentController extends Controller
{
    /**
     * 处理支付宝通知
     * @Anonymous
     */
    public function alipayAction()
    {
        $data = $this->request->get();
        $this->logger->debug(json_encode($this->request->get()));
        if ($data['app_id'] === AlipayOpen::APP_ID) {
            if (AlipayOpen::verify($data) === false) {
                $this->logger->debug('验签失败');
                $this->response->setStatusCode(Status::BadRequest);
                return;
            }
        } elseif (Alipay::verify($data) === false) {
            $this->logger->debug('验签失败');
            $this->response->setStatusCode(Status::BadRequest);
            return;
        }
        switch ($data['notify_type']) {
            case 'trade_status_sync':   // 处理收款异步通知
                if (in_array($data['trade_status'], ['TRADE_FINISHED', 'TRADE_SUCCESS'], true)) {
                    try {
                        $is_fake = !empty($data['extra_common_param']) && $data['extra_common_param'] == 'fake' ? true : false;
                        $now = time();
                        $this->db->begin();

                        if ($is_fake) { //刷单充值
                            $trade = TradeFake::findFirst([
                                'conditions' => 'SerialNumber=?0',
                                'bind'       => [$data['out_trade_no']],
                            ]);
                        } else { //正常充值
                            $trade = Trade::findFirst([
                                'conditions' => 'SerialNumber=?0',
                                'bind'       => [$data['out_trade_no']],
                            ]);
                        }

                        if (!$trade) {
                            $err = sprintf('出现丢单: %s', $data['out_trade_no']);
                            $this->logger->error($err);
                            throw new \LogicException($err);
                        }

                        if ($trade->Status === Trade::STATUS_COMPLETE) {
                            $err = sprintf('已手工修复订单: %s', $data['out_trade_no']);
                            $this->logger->error($err);
                            $this->response->setContent('success');
                            return;
                        }

                        if (!$is_fake) { //刷单不记录日志
                            $tradeLog = new TradeLog();
                            $tradeLog->TradeId = $trade->Id;
                            $tradeLog->Reason = $data['subject'];
                            $tradeLog->StatusBefore = $trade->Status;
                            $tradeLog->StatusAfter = Trade::STATUS_COMPLETE;
                            $tradeLog->Created = $now;
                            $tradeLog->UserId = 0;

                            $trade->Status = Trade::STATUS_COMPLETE;
                            $trade->Updated = $now;

                            if (!$trade->save() || !$tradeLog->save()) {
                                $err = sprintf('更新交易状态失败: %d', $trade->SerialNumber);
                                $this->logger->error($err);
                                throw new \LogicException($err);
                            }
                        }

                        if ($is_fake) {
                            $org = OrganizationFake::findFirst($trade->OrganizationId);
                        } else {
                            $org = Organization::findFirst($trade->OrganizationId);
                        }

                        if ($is_fake) {
                            $balance_field = 'BalanceFake';
                            $money_field = 'MoneyFake';
                        } else {
                            $balance_field = 'Balance';
                            $money_field = 'Money';
                        }

                        $org->$balance_field = new RawValue(sprintf($balance_field.'+%d', $trade->Amount));
                        $org->$money_field = new RawValue(sprintf($money_field.'+%d', $trade->Amount));
                        if (!$org->save()) {
                            $err = sprintf('更新金额失败: %d', $trade->SerialNumber);
                            $this->logger->error($err);
                            throw new \LogicException($err);
                        }
                        $org->refresh();

                        if (!$is_fake) {
                            $bill = new Bill();
                        $bill->Title = sprintf(BillTitle::Trade_In, $data['subject']);
                        $bill->Fee = Bill::inCome($trade->Amount);
                        $bill->Balance = $org->Balance;
                        $bill->OrganizationId = $org->Id;
                        $bill->UserId = $trade->UserId;
                        $bill->Type = Bill::TYPE_CHARGE;
                        $bill->Created = $now;
                        $bill->ReferenceType = Bill::REFERENCE_TYPE_TRADE;
                        $bill->ReferenceId = $trade->Id;
                        if (!$bill->save()) {
                            $err = sprintf('插入账单失败: %d', $trade->SerialNumber);
                            $this->logger->error($err);
                            throw new \LogicException($err);
                            }
                        }

                        if (!$is_fake) {
                            //发送消息
                            switch ($org->IsMain) {
                                case 1:
                                    MessageTemplate::send(
                                        $this->queue,
                                        null,
                                        MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH | MessageTemplate::METHOD_SMS,
                                        Push::TITLE_FUND,
                                        $org->Id,
                                        Event::MONEY,
                                        'fund_charge',
                                        MessageLog::TYPE_CHARGE,
                                        date('Y-m-d H:i:s', $trade->Created),
                                        Alipay::fen2yuan((int)$trade->Amount)
                                    );
                                    break;
                                case 2:
                                    MessageTemplate::send(
                                        $this->queue,
                                        UserEvent::user((int)$org->Id),
                                        MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH | MessageTemplate::METHOD_SMS,
                                        Push::TITLE_FUND,
                                        0,
                                        0,
                                        'fund_charge',
                                        MessageLog::TYPE_CHARGE,
                                        date('Y-m-d H:i:s', $trade->Created),
                                        Alipay::fen2yuan((int)$trade->Amount)
                                    );
                                    break;
                            }
                        }

                        $this->db->commit();
                        $this->response->setContent('success');
                    } catch (LogicException $e) {
                        $this->db->rollback();
                    } catch (ParamException $e) {
                        $this->db->rollback();
                    } catch (\Exception $e) {
                        $this->db->rollback();
                    }
                }
                break;
            default:
                $this->response->setStatusCode(Status::BadRequest);
                return;
        }
    }

    public function maxAmountAction()
    {
        $auth = $this->session->get('auth');
        if (!$auth) {
            return $this->response->setStatusCode(Status::Unauthorized);
        }
        $channel = $this->channels->get((int)$this->request->get('Gateway'));
        $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
        $result = [];
        $result['MaxAmount'] = $channel->getAvailable((int)$organization->Money);
        $result['FeeMoney'] = $channel->getFee($result['MaxAmount']);
        $result['Balance'] = $organization->Balance;

        return $this->response->setJsonContent($result);
    }

    public function calcFeeAction()
    {
        $channel = $this->channels->get((int)$this->request->get('Gateway'));
        $result = [];
        $result['FeeMoney'] = $channel->getFee((int)$this->request->get('Amount'));
        return $this->response->setJsonContent($result);
    }

    public function channelsAction()
    {
        return $this->response->setJsonContent($this->channels->map());
    }
}
