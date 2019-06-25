<?php

namespace App\Admin\Controllers;

use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\AlipayOpen;
use App\Libs\AlipayTarget;
use App\Libs\PaymentChannel\IPaymentChannel;
use App\Libs\PaymentChannel\PaymentChannelResult;
use App\Libs\Push;
use App\Models\Bill;
use App\Models\Event;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Trade;
use App\Models\TradeLog;
use App\Models\User;
use App\Models\UserEvent;
use GuzzleHttp\Client;
use Phalcon\Db\RawValue;

class PaymentController extends Controller
{
    private function payRequest(IPaymentChannel $channel, User $user, Trade $trade, TradeLog $tradeLog): PaymentChannelResult
    {
        if ($channel instanceof \App\Libs\PaymentChannel\Alipay) {
            $ret = new AlipayTarget();
            $ret->serialNo = $trade->SerialNumber;
            $ret->fee = $trade->Amount;
            $ret->account = APP_DEBUG ? 'daumjl8667@sandbox.com' : $trade->Account;
            $ret->remarks = $tradeLog->Reason;
            $ret->name = $user->Name;
            $params = AlipayOpen::payment($ret);
            $client = new Client();
            $response = $client->get(AlipayOpen::GATEWAY . '?' . $params);
            return $channel->handleEncashResult(json_decode($response->getBody(), true));
        }
        if ($channel instanceof \App\Libs\PaymentChannel\Wxpay) {
            $result = $this->wxpay->transfer->toBankCard([
                'partner_trade_no' => $trade->SerialNumber,
                'enc_bank_no'      => $trade->Account,
                'enc_true_name'    => $trade->Name,
                'bank_code'        => $trade->Bank,
                'amount'           => $trade->Amount,
                'desc'             => $tradeLog->Reason,
            ]);
            return $channel->handleEncashResult($result);
        }
        return null;
    }

    public function payAction()
    {
        $id = $this->request->get('Id');
        /** @var Trade $trade */
        $trade = Trade::findFirst([
            'conditions' => 'Id=?0 and Type=?1 and Status=?2',
            'bind'       => [$id, Trade::TYPE_ENCASH, Trade::STATUS_PENDING],
        ]);
        if (!$trade) {
            throw new LogicException('参数错误:订单不存在', Status::BadRequest);
        }
        if ($trade->Audit !== 1) {
            throw new LogicException('此单未审核或审核被拒绝', Status::BadRequest);
        }
        $user = User::findFirst($trade->UserId);
        if (!$user) {
            throw new LogicException('参数错误:用户不存在', Status::BadRequest);
        }
        $logs = TradeLog::find([
            'conditions' => 'TradeId=?0',
            'bind'       => [$trade->Id],
        ]);
        if (\count($logs) > 1) {
            throw new LogicException('此单有误，需要人工核实', Status::BadRequest);
        }
        $channel = $this->channels->get((int)$trade->Gateway);
        $log = $logs[0];
        $result = $this->payRequest($channel, $user, $trade, $log);

        if ($result->Success === true) {
            try {
                $exception = new ParamException(Status::InternalServerError);
                $now = time();
                $this->db->begin();
                // 创建新的交易日志
                $tradeLog = new TradeLog();
                $tradeLog->TradeId = $trade->Id;
                $tradeLog->Reason = $log->Reason;
                $tradeLog->StatusBefore = $trade->Status;
                $tradeLog->StatusAfter = Trade::STATUS_COMPLETE;
                $tradeLog->Created = $now;
                $tradeLog->UserId = 0;
                // 更新交易状态
                $trade->Status = Trade::STATUS_COMPLETE;
                $trade->Updated = $now;

                if (!$trade->save()) {
                    $exception->loadFromModel($trade);
                    throw $exception;
                }

                if (!$tradeLog->save()) {
                    $exception->loadFromModel($tradeLog);
                    throw $exception;
                }

                $belongOrganization = true;
                //是机构还是个人
                if ($trade->Belong === Trade::Belong_Organization) {
                    $org = Organization::findFirst($trade->OrganizationId);
                } else {
                    $org = OrganizationUser::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$trade->OrganizationId, $trade->UserId],
                    ]);
                    $org->Id = $org->OrganizationId;
                    $org->IsMain = 1;
                    $belongOrganization = false;
                }
                // 计算提现金额与手续费的总额
                $total = $channel->getTotal((int)$trade->Amount);
                $fee = $channel->getFee((int)$trade->Amount);

                // 更新金额
                $org->Balance = new RawValue(sprintf('Balance-%d', $total));
                if (!$org->save()) {
                    $exception->loadFromModel($org);
                    throw $exception;
                }
                $org->refresh();
                if ($org->Balance < 0) {
                    $err = sprintf('更新金额发现坏账: %d', $trade->SerialNumber);
                    $this->logger->error($err);
                    throw new LogicException(Status::InternalServerError, $err);
                }

                // 插入提现账单
                $bill = new Bill();
                $bill->Title = sprintf(BillTitle::Trade_Out, $tradeLog->Reason);
                $bill->Fee = Bill::outCome($trade->Amount);
                $bill->Balance = $org->Balance + $fee;
                $bill->OrganizationId = $org->Id;
                $bill->UserId = $trade->UserId;
                $bill->Type = Bill::TYPE_ENCASH;
                $bill->Created = $now;
                $bill->ReferenceType = Bill::REFERENCE_TYPE_TRADE;
                $bill->ReferenceId = $trade->Id;
                $bill->Belong = $belongOrganization ? Bill::Belong_Organization : Bill::Belong_Personal;
                if (!$bill->save()) {
                    $exception->loadFromModel($bill);
                    throw $exception;
                }
                // 插入提现手续费账单
                $bill = new Bill();
                $bill->Title = sprintf(BillTitle::Trade_Out_Fee, Alipay::fen2yuan((int)$trade->Amount), Alipay::fen2yuan($fee));
                $bill->Fee = Bill::outCome($fee);
                $bill->Balance = $org->Balance;
                $bill->OrganizationId = $org->Id;
                $bill->UserId = $trade->UserId;
                $bill->Type = Bill::TYPE_PAYMENT;
                $bill->Created = $now;
                $bill->ReferenceType = Bill::REFERENCE_TYPE_TRADE;
                $bill->ReferenceId = $trade->Id;
                $bill->Belong = $belongOrganization ? Bill::Belong_Organization : Bill::Belong_Personal;
                if (!$bill->save()) {
                    $exception->loadFromModel($bill);
                    throw $exception;
                }
                switch ($org->IsMain) {
                    case 1:
                        MessageTemplate::stack(
                            $this->queue,
                            null,
                            MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH | MessageTemplate::METHOD_SMS,
                            Push::TITLE_FUND,
                            $org->Id,
                            Event::MONEY,
                            'fund_complete_encash',
                            MessageLog::TYPE_ENCASH,
                            date('Y-m-d H:i:s', $trade->Created),
                            Alipay::fen2yuan((int)$trade->Amount)
                        );
                        break;
                    case 2:
                        MessageTemplate::stack(
                            $this->queue,
                            UserEvent::user((int)$org->Id),
                            MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH | MessageTemplate::METHOD_SMS,
                            Push::TITLE_FUND,
                            0,
                            0,
                            'fund_complete_encash',
                            MessageLog::TYPE_ENCASH,
                            date('Y-m-d H:i:s', $trade->Created),
                            Alipay::fen2yuan((int)$trade->Amount)
                        );
                        break;
                    case 3:
                        MessageTemplate::stack(
                            $this->queue,
                            null,
                            MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH | MessageTemplate::METHOD_SMS,
                            Push::TITLE_FUND,
                            $org->Id,
                            Event::MONEY,
                            'fund_complete_encash',
                            MessageLog::TYPE_ENCASH,
                            date('Y-m-d H:i:s', $trade->Created),
                            Alipay::fen2yuan((int)$trade->Amount)
                        );
                        break;
                }
                $this->db->commit();
                $this->response->setStatusCode(Status::OK);
            } catch (LogicException $e) {
                $this->db->rollback();
                throw $e;
            } catch (ParamException $e) {
                $this->db->rollback();
                throw $e;
            } catch (\Exception $e) {
                $this->db->rollback();
                throw $e;
            }
        } else {
            $this->response->setStatusCode(Status::BadRequest);
        }
        $this->response->setJsonContent($result);
    }

    private function queryRequest(IPaymentChannel $channel, Trade $trade)
    {
        if ($channel instanceof \App\Libs\PaymentChannel\Alipay) {
            $params = AlipayOpen::query($trade->SerialNumber);
            $client = new Client();
            $response = $client->get(AlipayOpen::GATEWAY . '?' . $params);
            $result = json_decode($response->getBody(), true);
            $data = $result['alipay_fund_trans_order_query_response'];
            if ($data['status'] === 'SUCCESS') {
                throw new LogicException('该订单已成功打款无法回滚，如有问题请联系技术人员。', Status::BadRequest);
            }
            if ($data['status'] === 'ORDER_NOT_EXIST') {
                //throw new LogicException('该订单并未接入支付宝，如果是因为出款账户余额不足请点重试，否则表示存在丢单，请务必联系技术人员修复。', Status::BadRequest);
                return;
            }
        }
        if ($channel instanceof \App\Libs\PaymentChannel\Wxpay) {
            $result = $this->wxpay->transfer->queryBankCardOrder($trade->SerialNumber);
            if ($result['return_code'] !== 'SUCCESS') {
                throw new LogicException($result['return_msg'], Status::BadRequest);
            }
            if ($result['result_code'] !== 'SUCCESS' && $result['err_code'] === 'ORDERNOTEXIST') {
                //throw new LogicException('该订单并未接入微信银行卡代付渠道，如果是因为出款账户余额不足请点重试，否则表示存在丢单，请务必联系技术人员修复。', Status::BadRequest);
                return;
            }
            if ($result['result_code'] !== 'SUCCESS') {
                throw new LogicException($result['err_code'] . ' | ' . $result['err_code_des'], Status::BadRequest);
            }
            switch ($result['status']) {
                case 'PROCESSING':
                    throw new LogicException('该订单正在处理中无法回滚，请过段时间重试。', Status::BadRequest);
                    break;
                case 'SUCCESS':
                    throw new LogicException('该订单已成功打款无法回滚，请联系技术人员修复此单。', Status::BadRequest);
                    break;
                case 'FAILED':
                    break;
                case 'BANK_FAIL':
                    break;

            }
        }
    }

    public function rollbackAction()
    {
        $id = $this->request->get('Id');
        $trade = Trade::findFirst([
            'conditions' => 'Id=?0 and Type=?1 and Status=?2',
            'bind'       => [$id, Trade::TYPE_ENCASH, Trade::STATUS_PENDING],
        ]);
        if (!$trade) {
            throw new LogicException('参数错误:订单不存在', Status::BadRequest);
        }
        $channel = $this->channels->get((int)$trade->Gateway);
        $reason = $this->request->get('Reason');
        if (!$reason) {
            $reason = '打款失败，请尝试更换' . $channel->getChannel() . '账号或者切换渠道重新发起提现。';
        }
        $exception = new ParamException(Status::InternalServerError);

        try {
            $this->queryRequest($channel, $trade);
            $now = time();
            $this->db->begin();
            // 创建新的交易日志
            $tradeLog = new TradeLog();
            $tradeLog->TradeId = $trade->Id;
            $tradeLog->Reason = $reason;
            $tradeLog->StatusBefore = $trade->Status;
            $tradeLog->StatusAfter = Trade::STATUS_COMPLETE;
            $tradeLog->Created = $now;
            $tradeLog->UserId = 0;
            // 更新交易状态
            $trade->Status = Trade::STATUS_CLOSE;
            $trade->Updated = $now;

            if (!$trade->save()) {
                $exception->loadFromModel($trade);
                throw $exception;
            }

            if (!$tradeLog->save()) {
                $exception->loadFromModel($tradeLog);
                throw $exception;
            }
            // 计算提现金额与手续费的总额

            $total = $channel->getTotal((int)$trade->Amount);
            // 审核不通过还原金额
            $org = Organization::findFirst($trade->OrganizationId);
            $org->Money = new RawValue(sprintf('Money+%d', $total));
            if (!$org->save()) {
                $exception->loadFromModel($org);
                throw $exception;
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::OK);
            $this->response->setJsonContent(['message' => '操作成功']);
        } catch (LogicException $e) {
            if ($this->db->isUnderTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        } catch (ParamException $e) {
            if ($this->db->isUnderTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        } catch (\Exception $e) {
            if ($this->db->isUnderTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    public function queryAction()
    {
        $id = $this->request->get('Id');
        $trade = Trade::findFirst([
            'conditions' => 'Id=?0 and Type=?1',
            'bind'       => [$id, Trade::TYPE_ENCASH],
        ]);
        $channel = $this->channels->get((int)$trade->Gateway);
        if ($channel instanceof \App\Libs\PaymentChannel\Alipay) {
            $params = AlipayOpen::query($trade->SerialNumber);
            $client = new Client();
            $response = $client->get(AlipayOpen::GATEWAY . '?' . $params);
            $result = json_decode($response->getBody(), true);
            $data = $result['alipay_fund_trans_order_query_response'];
            $this->response->setJsonContent($data);
        }
        if ($channel instanceof \App\Libs\PaymentChannel\Wxpay) {
            $result = $this->wxpay->transfer->queryBankCardOrder($trade->SerialNumber);
            $this->response->setJsonContent($result);
        }
    }
}