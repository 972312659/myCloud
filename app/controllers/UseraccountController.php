<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/2
 * Time: 4:28 PM
 */

namespace App\Controllers;


use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\Push;
use App\Models\Bill;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Trade;
use App\Models\TradeLog;
use Phalcon\Db\RawValue;
use Phalcon\Paginator\Adapter\QueryBuilder;
use Phalcon\Validation;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\PresenceOf;

class UseraccountController extends Controller
{
    /**
     * 个人账户信息（最大提现金额）
     */
    public function maxAmountAction()
    {
        $auth = $this->session->get('auth');
        $channel = $this->channels->get((int)$this->request->get('Gateway'));
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1',
            'bind'       => [$auth['OrganizationId'], $auth['Id']],
        ]);
        $result = [];
        $result['MaxAmount'] = $channel->getAvailable((int)$organizationUser->Money);
        $result['FeeMoney'] = $channel->getFee($result['MaxAmount']);
        $result['Balance'] = $organizationUser->Balance;

        return $this->response->setJsonContent($result);
    }

    /**
     * 账单
     */
    public function billListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $criteria = $this->modelsManager->createBuilder()
            ->columns('B.Id,B.Title,B.Fee,B.Type,B.Balance,B.Created')
            ->addFrom(Bill::class, 'B')
            ->where('B.OrganizationId=:OrganizationId:', ['OrganizationId' => $this->user->OrganizationId])
            ->andWhere('B.UserId=:UserId:', ['UserId' => $this->user->Id])
            ->andWhere('B.Belong=:Belong:', ['Belong' => Bill::Belong_Personal])
            ->andWhere('B.IsDeleted=:IsDeleted:', ['IsDeleted' => Bill::IsDeleted_No]);
        //按时间查询
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $criteria->andWhere("B.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('结束时间不得早于开始时间', Status::BadRequest);
            }
            $criteria->andWhere("B.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        $criteria->orderBy('B.Id desc');
        $paginate = new QueryBuilder([
            'builder' => $criteria,
            'limit'   => $this->request->get('PageSize') ?: 10,
            'page'    => $this->request->get('Page') ?: 1,
        ]);
        $pages = $paginate->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            switch ($data['Type']) {
                case Bill::TYPE_CHARGE:
                    $data['TypeName'] = '充值';
                    break;
                case Bill::TYPE_ENCASH:
                    $data['TypeName'] = '提现';
                    break;
                case Bill::TYPE_PROFIT:
                    $data['TypeName'] = '收入';
                    break;
                case Bill::TYPE_PAYMENT:
                    $data['TypeName'] = '支付';
                    break;
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 申请提现
     */
    public function encashAction()
    {
        if ($this->request->isPost()) {
            $exception = new ParamException(Status::BadRequest);
            // 验证渠道
            $validation = new Validation();
            $validation->rules('Gateway', [
                new PresenceOf(['message' => '请选择提现渠道']),
                new InclusionIn(['message' => '不支持的提现渠道', 'domain' => $this->channels->options()]),
            ]);
            $ret = $validation->validate($this->request->get());
            if (\count($ret) > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }

            $channel = $this->channels->get((int)$this->request->get('Gateway'));
            $ret = $channel->encashValidation()->validate($this->request->get());
            if (\count($ret) > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }

            if (!$this->security->checkHash($this->request->get('Password'), $this->user->Password)) {
                $exception->add('Password', '登录密码错误');
                throw $exception;
            }

            $amount = (int)$this->request->get('Amount');
            $gateway = $this->request->get('Gateway');
            $account = $this->request->get('Account');
            $name = $this->request->get('Name');
            $bank = $this->request->get('Bank');

            try {
                /**
                 * @var OrganizationUser $orgUser
                 */
                $this->db->begin();
                // 扣除可用余额
                $orgUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$this->user->OrganizationId, $this->user->Id],
                ]);
                /** @var Organization $organization */
                $organization = Organization::findFirst(sprintf('Id=%d', $orgUser->OrganizationId));
                //验证最大可提现
                $max = $channel->getAvailable((int)$orgUser->Money);
                if ($amount > $max) {
                    throw new LogicException('最大可提现额度为:' . Alipay::fen2yuan($max), Status::BadRequest);
                }
                $orgUser->Money = new RawValue(sprintf('Money-%d', $channel->getTotal($amount)));
                if ($orgUser->update() === false) {
                    $exception->loadFromModel($orgUser);
                    throw $exception;
                }
                // 余额不足回滚
                $orgUser->refresh();
                if ($orgUser->Money < 0 || $orgUser->Balance < 0) {
                    throw new LogicException('可提现余额不足', Status::BadRequest);
                }
                $now = time();
                // 产生交易单
                $trade = new Trade();
                $trade->Gateway = $gateway;
                $trade->Account = $account;
                $trade->UserId = $this->user->Id;
                $trade->Name = $name;
                $trade->Bank = $bank;
                $trade->Status = Trade::STATUS_PENDING;
                $trade->Created = $now;
                $trade->Updated = $now;
                $trade->SerialNumber = $now << 32 | $this->user->Id;
                $trade->OrganizationId = $this->user->OrganizationId;
                $trade->HospitalId = $this->session->get('auth')['HospitalId'];
                $trade->Amount = $amount;
                $trade->Belong = Trade::Belong_Personal;
                $trade->Type = Trade::TYPE_ENCASH;
                if ($trade->save() === false) {
                    $exception->loadFromModel($trade);
                    throw $exception;
                }

                $tradeLog = new TradeLog();
                $tradeLog->TradeId = $trade->Id;
                $tradeLog->StatusBefore = Trade::STATUS_BLANK;
                $tradeLog->StatusAfter = Trade::STATUS_PENDING;
                $tradeLog->UserId = $this->user->Id;
                $tradeLog->Reason = sprintf('%s - %s 提现 %s 元', $organization->Name, $this->user->Name, Alipay::fen2yuan($amount));
                $tradeLog->Created = $now;
                if ($tradeLog->save() === false) {
                    $exception->loadFromModel($tradeLog);
                    throw $exception;
                }
                //发送消息
                MessageTemplate::send(
                    $this->queue,
                    $this->user,
                    MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH | MessageTemplate::METHOD_SMS,
                    Push::TITLE_FUND,
                    0,
                    0,
                    'fund_apply_encash',
                    MessageLog::TYPE_ENCASH,
                    date('Y-m-d H:i:s', $now),
                    Alipay::fen2yuan($amount)
                );
                $this->db->commit();
                $this->response->setStatusCode(Status::OK);
                $this->response->setJsonContent([
                    'Id'      => $trade->Id,
                    'message' => '提现申请已提交，我们会在3个工作日内处理。',
                ]);
            } catch (LogicException $e) {
                $this->db->rollback();
                throw $e;
            } catch (ParamException $e) {
                $this->db->rollback();
                throw $e;
            }
            return;
        }
        $this->response->setStatusCode(Status::MethodNotAllowed);
    }
}