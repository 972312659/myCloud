<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/2
 * Time: 4:33 PM
 */

namespace App\Libs\salesman;

use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\Push;
use App\Models\Bill;
use App\Models\InteriorTrade;
use App\Models\InteriorTradeAndTransfer;
use App\Models\MessageLog;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\SalesmanBonus as SalesmanBonusModel;
use App\Models\SalesmanBonusRule;
use App\Models\Transfer;
use App\Models\User;
use Phalcon\Db\RawValue;
use Phalcon\Di\FactoryDefault;

class TransferBonus
{
    /** @var  OrganizationUser */
    protected $salesman;
    /** @var  array */
    protected $auth;

    public function __construct()
    {
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    public function create(InteriorTrade $interiorTrade, Bonus $bonus)
    {
        /** @var InteriorTradeAndTransfer $interiorTradeAndTransfer */
        $interiorTradeAndTransfer = InteriorTradeAndTransfer::findFirst([
            'conditions' => 'InteriorTradeId=?0',
            'bind'       => [$interiorTrade->Id],
        ]);
        if ($interiorTradeAndTransfer) {
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst(sprintf('Id=%d', $interiorTradeAndTransfer->TransferId));
            if ($transfer) {

                //奖励单
                $salesmanBonus = SalesmanBonusModel::findFirst([
                    'conditions' => 'OrganizationId=?0 and ReferenceType=?1 and ReferenceId=?2',
                    'bind'       => [$this->auth['OrganizationId'], $bonus->ReferenceType, $bonus->ReferenceId],
                ]);

                if ($bonus->Bonus && $bonus->Bonus > 0) {
                    $exp = new ParamException(Status::BadRequest);
                    try {
                        $money = $bonus->Bonus;

                        if (!$salesmanBonus) {
                            $salesmanBonus = new SalesmanBonusModel();
                        }
                        $salesmanBonus->UserId = $bonus->UserId;
                        $salesmanBonus->OrganizationId = $this->auth['OrganizationId'];
                        $salesmanBonus->ReferenceType = $bonus->ReferenceType;
                        $salesmanBonus->ReferenceId = $bonus->ReferenceId;
                        $salesmanBonus->Describe = sprintf(SalesmanBonusModel::describe($bonus->ReferenceType), $interiorTrade->AcceptOrganizationName);
                        $salesmanBonus->Amount = $bonus->Amount;
                        $salesmanBonus->IsFixed = $bonus->IsFixed;
                        $salesmanBonus->Value = $bonus->Value;
                        $salesmanBonus->Bonus = $money;
                        $salesmanBonus->Status = SalesmanBonusModel::STATUS_FINANCE;

                        if (!$salesmanBonus->Bonus) {
                            $salesmanBonus->delete();
                        } else {
                            if (!$salesmanBonus->save()) {
                                $exp->loadFromModel($salesmanBonus);
                                throw $exp;
                            }
                        }

                    } catch (ParamException $e) {
                        throw $e;
                    }
                } else {
                    if ($salesmanBonus) {
                        $salesmanBonus->delete();
                    }
                }
            }
        }


    }

    /**
     * 计算实时奖励金额
     */
    public function bonusMoney(Transfer $transfer)
    {
        $bonus = new Bonus();

        //确保是自有转诊单，转诊单金额不为0
        if ($transfer->Genre == Transfer::GENRE_SELF && $transfer->Cost > 0) {
            /** @var OrganizationRelationship $slave */
            $slave = OrganizationRelationship::findFirst([
                'conditions' => 'MainId=?0 and MinorId=?1',
                'bind'       => [$this->auth['OrganizationId'], $transfer->SendOrganizationId],
            ]);
            /** @var OrganizationUser $salesman */
            $salesman = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$this->auth['OrganizationId'], $slave->SalesmanId],
            ]);
            $this->salesman = $salesman;
            //确保是业务经理
            if ($this->salesman->IsSalesman === OrganizationUser::Is_Salesman_Yes) {
                /** @var SalesmanBonusRule $salesmanBonusRule */
                $salesmanBonusRule = SalesmanBonusRule::findFirst([
                    'conditions' => 'Type=?0 and OrganizationId=?1 and UserId=?2',
                    'bind'       => [SalesmanBonusRule::Type_TransferCost, $this->auth['OrganizationId'], $this->salesman->UserId],
                ]);
                /** @var User $user */
                $user = User::findFirst(sprintf('Id=%d', $salesmanBonusRule->UserId));
                //奖励不为0的时候生成
                if ($salesmanBonusRule && $salesmanBonusRule->Value > 0) {
                    $bonus->UserId = $this->salesman->UserId;
                    $bonus->OrganizationId = $this->salesman->OrganizationId;
                    $bonus->UserName = $user->Name;
                    $bonus->ReferenceType = SalesmanBonusModel::ReferenceType_Transfer;
                    $bonus->ReferenceId = $transfer->Id;
                    $bonus->Amount = $transfer->Cost;
                    $bonus->IsFixed = $salesmanBonusRule->IsFixed;
                    $bonus->Value = $salesmanBonusRule->Value;
                    $bonus->Bonus = (int)floor($salesmanBonusRule->IsFixed ? $salesmanBonusRule->Value : ($transfer->Cost * $salesmanBonusRule->Value / 10000));
                }
            }
        }

        return $bonus;
    }

    /**
     * 已经提交财务审核的奖励金额
     * @param SalesmanBonusModel $salesmanBonus
     * @return Bonus
     */
    public function salesmanBonus($salesmanBonus)
    {
        $bonus = new Bonus();
        if ($salesmanBonus) {
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $salesmanBonus->UserId));
            $bonus->UserId = $salesmanBonus->UserId;
            $bonus->OrganizationId = $salesmanBonus->OrganizationId;
            $bonus->UserName = $user->Name;
            $bonus->ReferenceType = $salesmanBonus->ReferenceType;
            $bonus->ReferenceId = $salesmanBonus->ReferenceId;
            $bonus->Amount = $salesmanBonus->Amount;
            $bonus->IsFixed = $salesmanBonus->IsFixed;
            $bonus->Value = $salesmanBonus->Value;
            $bonus->Bonus = $salesmanBonus->Bonus;
        }
        return $bonus;
    }

    /**
     * 结算
     * @param InteriorTrade $interiorTrade
     * @param SalesmanBonusModel $salesmanBonus
     * @param Transfer $transfer
     * @throws ParamException
     */
    public function payment(InteriorTrade $interiorTrade, $salesmanBonus, Transfer $transfer)
    {
        if (!$salesmanBonus && !$salesmanBonus->Bonus) {
            if ($salesmanBonus) $salesmanBonus->delete();
            return;
        }
        $exp = new ParamException(Status::BadRequest);
        try {
            $money = $salesmanBonus->Bonus;

            //增加个人金额
            /** @var OrganizationUser $salesman */
            $salesman = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$salesmanBonus->OrganizationId, $salesmanBonus->UserId],
            ]);
            $salesman->Money = new RawValue(sprintf('Money+%d', $money));
            $salesman->Balance = new RawValue(sprintf('Balance+%d', $money));
            if (!$salesman->save()) {
                $exp->loadFromModel($salesman);
                throw $exp;
            }
            $salesman->refresh();

            //生成个人bill
            $bill = new Bill();
            $bill->Title = sprintf(BillTitle::Salesman_Bonus_TransferCost, $interiorTrade->AcceptOrganizationName, $transfer->OrderNumber, Alipay::fen2yuan((int)$money));
            $bill->OrganizationId = $salesman->OrganizationId;
            $bill->Fee = Bill::inCome($money);
            $bill->Balance = $salesman->Balance;
            $bill->UserId = $salesman->UserId;
            $bill->Type = Bill::TYPE_PROFIT;
            $bill->Created = time();
            $bill->ReferenceType = Bill::REFERENCE_TYPE_SALESMAN_BONUS;
            $bill->ReferenceId = $salesmanBonus->Id;
            $bill->Belong = Bill::Belong_Personal;
            if ($bill->save($bill) === false) {
                $exp->loadFromModel($bill);
                throw $exp;
            }

            //改变状态
            $salesmanBonus->Status = SalesmanBonusModel::STATUS_CASHIER_PAYMENT;
            if ($salesmanBonus->save() === false) {
                $exp->loadFromModel($salesmanBonus);
                throw $exp;
            }

            //消息
            MessageTemplate::send(
                FactoryDefault::getDefault()->get('queue'),
                $salesman,
                MessageTemplate::METHOD_MESSAGE,
                Push::TITLE_FUND,
                0,
                0,
                'transfer_salesman_bonus',
                MessageLog::TYPE_SALESMAN_BONUS,
                $interiorTrade->AcceptOrganizationName,
                $transfer->OrderNumber,
                Alipay::fen2yuan($money)
            );
        } catch (ParamException $e) {
            throw $e;
        }
    }
}