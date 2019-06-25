<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/1
 * Time: 17:28
 */

namespace App\Controllers;

use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\RedisName;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\AlipayOpen;
use App\Libs\csv\FrontCsv;
use App\Libs\module\ManagerOrganization;
use App\Libs\Push;
use App\Models\Bill;
use App\Models\MessageLog;
use App\Models\OfflinePay;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\OrganizationUser;
use App\Models\PlatformLicensing;
use App\Models\PlatformLicensingOrder;
use App\Models\Trade;
use App\Models\TradeLog;
use Phalcon\Db\RawValue;
use Phalcon\Paginator\Adapter\QueryBuilder;
use Phalcon\Validation;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\PresenceOf;

class BillController extends Controller
{
    /**
     * 商家提现
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
                 * @var Organization $org
                 */
                $this->db->begin();
                // 扣除可用余额
                $org = Organization::findFirst($this->user->OrganizationId);
                //验证最大可提现
                $max = $channel->getAvailable((int)$org->Money);
                if ($amount > $max) {
                    throw new LogicException('最大可提现额度为:' . Alipay::fen2yuan($max), Status::BadRequest);
                }
                $org->Money = new RawValue(sprintf('Money-%d', $channel->getTotal($amount)));
                if ($org->update() === false) {
                    $exception->loadFromModel($org);
                    throw $exception;
                }
                // 余额不足回滚
                $org->refresh();
                if ($org->Money < 0 || $org->Balance < 0) {
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
                $tradeLog->Reason = sprintf('%s - %s 提现 %s 元', $org->Name, $this->user->Name, Alipay::fen2yuan($amount));
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

    /**
     * 商家充值到平台
     */
    public function chargeAction()
    {
        if ($this->request->isPost()) {
            $exception = new ParamException(Status::BadRequest);
            $gateway = (int)$this->request->getPost('Gateway');
            if ($gateway !== Trade::GATEWAY_ALIPAY) {
                $exception->add('Gateway', '充值渠道不支持');
                throw $exception;
            }
            $amount = (int)$this->request->getPost('Amount');
            if ($amount <= 0 || $amount > 10000000000) {
                $exception->add('Amount', '充值金额错误');
                throw $exception;
            }
            try {
                /**
                 * @var Organization $org
                 */
                $this->db->begin();
                // 扣除余额
                $org = $this->user->Organization;

                $now = time();
                $trade = new Trade();
                // 产生交易单
                $trade->Gateway = $this->request->getPost('Gateway');
                $trade->Account = $this->request->getPost('Account');
                $trade->Status = Trade::STATUS_PENDING;
                $trade->UserId = $this->user->Id;
                $trade->Created = $now;
                $trade->Updated = $now;
                $trade->SerialNumber = $now << 32 | $this->user->Id;
                $trade->OrganizationId = $this->user->OrganizationId;
                $trade->Amount = $amount;
                $trade->Type = Trade::TYPE_CHARGE;
                if ($trade->save() === false) {
                    $exception->loadFromModel($trade);
                    throw $exception;
                }

                $tradeLog = new TradeLog();
                $tradeLog->TradeId = $trade->Id;
                $tradeLog->StatusBefore = Trade::STATUS_BLANK;
                $tradeLog->StatusAfter = Trade::STATUS_PENDING;
                $tradeLog->UserId = $this->user->Id;
                $tradeLog->Reason = sprintf('%s - %s 充值 %s 元', $org->Name, $this->user->Name, Alipay::fen2yuan($amount));
                $tradeLog->Created = $now;
                if ($tradeLog->save() === false) {
                    $exception->loadFromModel($tradeLog);
                    throw $exception;
                }
                $this->db->commit();
            } catch (LogicException $e) {
                $this->db->rollback();
                throw $e;
            } catch (ParamException $e) {
                $this->db->rollback();
                throw $e;
            }
        } elseif ($this->request->isGet()) {
            $trade = Trade::findFirst([
                'conditions' => 'Id=?0 and OrganizationId=?1 and Type=?2 and Status=?3',
                'bind'       => [$this->request->get('Id'), $this->user->OrganizationId, Trade::TYPE_CHARGE, Trade::STATUS_PENDING],
            ]);
            if (!$trade) {
                throw new LogicException('参数错误或记录不存在', Status::BadRequest);
            }
            $tradeLog = TradeLog::findFirst([
                'conditions' => 'TradeId=?0 and StatusBefore=?1',
                'bind'       => [$trade->Id, Trade::STATUS_BLANK],
            ]);
        } else {
            $this->response->setStatusCode(Status::MethodNotAllowed);
            return;
        }
        $this->response->setStatusCode(Status::OK);
        if ($this->session->isApp()) {
            $url = AlipayOpen::receipt($trade->SerialNumber, $tradeLog->Reason, $trade->Amount);
        } else {
            $url = Alipay::receipt($trade->SerialNumber, $tradeLog->Reason, $trade->Amount);
        }
        $this->response->setJsonContent([
            'url' => $url,
        ]);
    }

    public function listAction()
    {
        //todo 去掉特殊处理
        $auth = $this->session->get('auth');
        //处理
        if ($auth['HospitalId'] != $auth['OrganizationId'] && $auth['HospitalId'] == 3301) {
            return;
        }

        $criteria = $this->modelsManager->createBuilder();
        $criteria->columns('B.Id,B.Title,B.Fee,B.Balance,B.Created');
        $criteria->addFrom(Bill::class, 'B');
        $criteria->where('B.OrganizationId=:OrganizationId:', ['OrganizationId' => $this->user->OrganizationId]);
        $criteria->andWhere('B.IsDeleted=:IsDeleted:', ['IsDeleted' => Bill::IsDeleted_No]);
        $criteria->orderBy('B.Id desc');
        //app输入id查询
        $id = $this->request->get('Id', 'int');
        if (is_numeric($id)) {
            $criteria->andWhere(sprintf('B.Id<%d', $id));
        }
        $paginate = new QueryBuilder([
            'builder' => $criteria,
            'limit'   => $this->request->get('PageSize') ?: 10,
            'page'    => $this->request->get('Page') ?: 1,
        ]);
        $this->outputPagedJson($paginate);
    }

    /**
     * 账单明细
     */
    public function detailAction()
    {
        $bill = Bill::findFirst([
            'conditions' => 'Id=?0 and OrganizationId=?1',
            'bind'       => [$this->request->get('Id'), $this->user->OrganizationId],
        ]);
        $this->response->setJsonContent($bill);
    }

    /**
     * 交易记录列表
     */
    public function recordsAction()
    {
        //todo 去掉特殊处理
        $auth = $this->session->get('auth');
        //处理
        if ($auth['HospitalId'] != $auth['OrganizationId'] && $auth['HospitalId'] == 3301) {
            return;
        }
        $now = time();
        $records = Trade::find([
            'conditions' => 'Type=1 and Status=1 and UserId=?0 and Created<?1',
            'bind'       => [$this->user->Id, $now - 1800],
        ]);
        $records->rewind();
        while ($records->valid()) {
            $record = $records->current();
            $record->Status = Trade::STATUS_CLOSE;
            $record->save();
            $log = new TradeLog();
            $log->TradeId = $record->Id;
            $log->UserId = $this->user->Id;
            $log->StatusBefore = Trade::STATUS_PENDING;
            $log->StatusAfter = Trade::STATUS_CLOSE;
            $log->Created = $now;
            $log->Reason = '交易过期关闭';
            $log->save();
            $records->next();
        }
        /**
         * @var  \Phalcon\Mvc\Model\Criteria $criteria
         */
        $criteria = Trade::query();
        $criteria->where('OrganizationId=:OrganizationId:');
        $bind['OrganizationId'] = $this->user->OrganizationId;
        $data = $this->request->get();
        //创建时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $criteria->andWhere("Created>=:StartTime:");
            $bind['StartTime'] = $data['StartTime'];
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $this->response->setStatusCode(Status::BadRequest);
                return;
            }
            $criteria->andWhere("Created<=:EndTime:");
            $bind['EndTime'] = $data['EndTime'] + 86400;
        }
        //交易渠道
        if (!empty($data['Gateway']) && isset($data['Gateway']) && is_numeric($data['Gateway'])) {
            $criteria->andWhere("Gateway=:Gateway:");
            $bind['Gateway'] = $data['Gateway'];
        }
        //交易方式
        if (!empty($data['Type']) && isset($data['Type']) && is_numeric($data['Type'])) {
            $criteria->andWhere("Type=:Type:");
            $bind['Type'] = $data['Type'];
        }
        //交易状态
        if (!empty($data['Status']) && isset($data['Status']) && is_numeric($data['Status'])) {
            $criteria->andWhere("Status=:Status:");
            $bind['Status'] = $data['Status'];
        }
        //交易金额
        if (!empty($data['MaxAmount']) && isset($data['MaxAmount'])) {
            $criteria->andWhere("Amount<=:MaxAmount:");
            $bind['MaxAmount'] = $data['MaxAmount'];
        }
        if (!empty($data['MinAmount']) && isset($data['MinAmount'])) {
            if (!empty($data['MaxAmount']) && !empty($data['MinAmount']) && ($data['MinAmount'] > $data['MaxAmount'])) {
                $this->response->setStatusCode(Status::BadRequest);
                return;
            }
            $criteria->andWhere("Amount>=:MinAmount:");
            $bind['MinAmount'] = $data['MinAmount'];
        }
        $criteria->bind($bind);
        $criteria->orderBy('Id desc');
        $paginate = new QueryBuilder([
            'builder' => $criteria->createBuilder(),
            'limit'   => 10,
            'page'    => $this->request->get('Page') ?: 1,
        ]);
        $this->outputPagedJson($paginate);
    }

    /**
     * 交易记录
     */
    public function recordAction()
    {
        $id = (int)$this->request->get('Id');
        if ($id === 0) {
            $id = (int)$this->request->get('id');
        }
        $orgId = $this->user->OrganizationId;
        $trade = Trade::findFirst([
            'conditions' => 'Id=?0 and OrganizationId=?1',
            'bind'       => [$id, $orgId],
        ]);
        if (!$trade) {
            throw new LogicException('记录不存在', Status::BadRequest);
        }
        $tradeLogs = TradeLog::find([
            'conditions' => 'TradeId=?0',
            'bind'       => [$trade->Id],
        ]);
        $this->response->setJsonContent(array_merge($trade->toArray(),
            ['Logs' => $tradeLogs]
        ));
    }

    /**
     * 平台使用费续费
     */
    public function replenishAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $now = time();
            $auth = $this->session->get('auth');
            $platformLicensingId = $this->request->get('PlatformLicensingId', 'int');
            $platformLicensing = PlatformLicensing::findFirst(sprintf('Id=%d', $platformLicensingId));
            if (!$platformLicensing) {
                throw $exception;
            }
            if ($platformLicensing->Status == PlatformLicensing::STATUS_OFF) {
                throw new LogicException('已下架', Status::BadRequest);
            }
            /** @var Organization $organization */
            $organization = Organization::findFirst(sprintf('Id=%d', $auth['HospitalId']));
            if ($organization->Balance < $platformLicensing->Price) {
                throw new LogicException('余额不足', Status::BadRequest);
            }
            //购买次数
            if ($platformLicensing->Limited > 0) {
                $count = PlatformLicensingOrder::count(sprintf('OrganizationId=%d', $organization->Id));
                if ($count >= $platformLicensing->Limited) {
                    throw new LogicException('已达到购买次数上限', Status::BadRequest);
                }
            }
            $organization->Balance = new RawValue(sprintf('Balance-%d', $platformLicensing->Price));
            $organization->Money = $organization->Money - $platformLicensing->Price > 0 ? new RawValue(sprintf('Money-%d', $platformLicensing->Price)) : new RawValue(sprintf('Money-%d', $organization->Money));
            $long = $platformLicensing->Durations;
            $today = date('Y-m-d');
            $expire = $organization->Expire;
            $date = $expire > $today ? $expire : $today;
            $day = mb_substr($date, -2, 2);
            if ($day >= 28) {//如果是28日后，下次到期就是当月的最后一天
                $date = date('Y-m-d', strtotime("$date -4 day"));
                $date = date('Y-m-d', strtotime("$date +$long month"));
                $begin = mb_substr($date, 0, 7) . '-01';
                $date = date('Y-m-d', strtotime("$begin +1 month -1 day"));
            } else {
                $date = date('Y-m-d', strtotime("$date +$long month"));
            }
            $organization->Expire = $date;
            if ($organization->save() === false) {
                $exception->loadFromModel($organization);
                throw $exception;
            }
            $organization->refresh();
            //生成账单
            $money = (int)$platformLicensing->Price;
            $bill = new Bill();
            $bill->Title = sprintf(BillTitle::PlatformLicensing_Hospital, $auth['OrganizationName'], $platformLicensing->Name, $auth['Name'], Alipay::fen2yuan($money));
            $bill->OrganizationId = $organization->Id;
            $bill->Fee = Bill::outCome($money);
            $bill->Balance = $organization->Balance;
            $bill->UserId = $auth['Id'];
            $bill->Type = Bill::TYPE_PAYMENT;
            $bill->Created = $now;
            $bill->ReferenceType = Bill::REFERENCE_TYPE_PLATFORMLICENSING;
            $bill->ReferenceId = $platformLicensing->Id;
            if ($bill->save() === false) {
                $exception->loadFromModel($bill);
                throw $exception;
            }
            //平台账户变动
            $peach = Organization::findFirst(Organization::PEACH);
            $peach->Balance = $peach->Balance + $platformLicensing->Price;
            $peach->Money = $peach->Money + $platformLicensing->Price;
            if ($peach->save() === false) {
                $exception->loadFromModel($peach);
                throw $exception;
            }
            $peach->refresh();
            //平台账单
            $bill_platform = new Bill();
            $bill_platform->Title = sprintf(BillTitle::PlatformLicensing_Platform, $auth['OrganizationName'], $auth['Name'], $platformLicensing->Name, Alipay::fen2yuan($money));
            $bill_platform->OrganizationId = $peach->Id;
            $bill_platform->Fee = Bill::inCome($money);
            $bill_platform->Balance = $peach->Balance;
            $bill_platform->UserId = $auth['Id'];
            $bill_platform->Type = Bill::TYPE_PROFIT;
            $bill_platform->Created = $now;
            $bill_platform->ReferenceType = Bill::REFERENCE_TYPE_PLATFORMLICENSING;
            $bill_platform->ReferenceId = $platformLicensing->Id;
            if ($bill_platform->save() === false) {
                $exception->loadFromModel($bill_platform);
                throw $exception;
            }
            //生成订单
            $order = new PlatformLicensingOrder();
            $order->PlatformLicensingId = $platformLicensing->Id;
            $order->Name = $platformLicensing->Name;
            $order->Price = $platformLicensing->Price;
            $order->Durations = $platformLicensing->Durations;
            $order->OrganizationId = $auth['OrganizationId'];
            $order->OrganizationName = $auth['OrganizationName'];
            $order->UserId = $auth['Id'];
            $order->UserName = $auth['Name'];
            $order->Created = $now;
            if ($order->save() === false) {
                $exception->loadFromModel($order);
                throw $exception;
            }
            //更新OrganizationModule
            if ($organization->IsMain == Organization::ISMAIN_HOSPITAL) {
                ManagerOrganization::updateOrganizationModule($organization->Id, $organization->Expire, $auth['Name']);
            }
            $this->db->commit();
            //如果已过期的时候充值，清除缓存
            if ($today > $expire) {
                $organizationUsers = OrganizationUser::find([
                    'conditions' => 'OrganizationId=?0',
                    'bind'       => [$auth['OrganizationId']],
                ])->toArray();
                $ids = [$auth['Id']];
                if (count($organizationUsers)) {
                    $ids = array_unique(array_merge($ids, array_column($organizationUsers, 'UserId')));
                }
                $this->session->destroy('auth');
                foreach ($ids as $id) {
                    $this->redis->delete(RedisName::Permission . $organization->Id . '_' . $id);
                }
            }
            $this->response->setJsonContent(['Expire' => date('Y年m月d日', strtotime($organization->Expire))]);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 平台续费包列表
     */
    public function platformLicensingListAction()
    {
        $result = PlatformLicensing::find([
            'conditions' => 'Status=?0',
            'bind'       => [PlatformLicensing::STATUS_ON],
            'order'      => 'Id Desc',
        ]);
        $this->response->setJsonContent($result);
    }

    /**
     * 平台购买记录
     */
    public function platformLicensingOrderAction()
    {
        $result = PlatformLicensingOrder::find([
            'conditions' => 'OrganizationId=?0',
            'bind'       => [$this->user->OrganizationId],
            'order'      => 'Id desc',
        ]);
        $this->response->setJsonContent($result);
    }

    /**
     * 支出/收入 记录
     */
    public function accountAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['B.Id', 'B.Title', 'B.Fee', 'B.ReferenceType as Type', 'B.Created', 'B.Balance'])
            ->addFrom(Bill::class, 'B')
            ->where('OrganizationId=:OrganizationId:', ['OrganizationId' => $this->user->OrganizationId])
            ->andWhere(sprintf('B.IsDeleted=%d', Bill::IsDeleted_No))
            ->andWhere(sprintf('B.Belong=%d', Bill::Belong_Organization));
        //创建时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("B.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $this->response->setStatusCode(Status::BadRequest);
                return;
            }
            $query->andWhere("B.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        if (isset($data['Type']) && is_numeric($data['Type'])) {
            $query->andWhere('B.ReferenceType=:Type:', ['Type' => $data['Type']]);
        }
        switch ($data['Way']) {
            case 'Pay':
                $query->andWhere('B.Fee<0');
                break;
            case 'Income':
                $query->andWhere('B.Fee>0');
                break;
        }
        switch ($data['Sort']) {
            case 'Asc':
                $query->orderBy('Id asc');
                break;
            default:
                $query->orderBy('Id desc');
        }
        if (isset($data['Excel']) && is_numeric($data['Excel'])) {
            if ((!isset($data['StartTime']) || empty($data['StartTime'])) || (!isset($data['EndTime']) || empty($data['EndTime']))) {
                throw new LogicException('未选择起止时间', Status::BadRequest);
            }
            if (($data['EndTime'] - $data['StartTime']) / 86400 > 92) {
                throw new LogicException('导出时间不能超过三个月', Status::BadRequest);
            }
            $csv = new FrontCsv($query);
            $csv->bill($data['Way'] == 'Pay');
        }
        //app输入id查询
        if (isset($data['Id']) && is_numeric($data['Id'])) {
            switch ($data['Sort']) {
                case 'Asc':
                    $query->andWhere(sprintf('B.Id>%d', $data['Id']));
                    break;
                default:
                    $query->andWhere(sprintf('B.Id<%d', $data['Id']));
            }
            $page = 1;
        }
        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['TypeName'] = $data['Fee'] > 0 ? Bill::REFERENCE_TYPE_NAME_INCOME[$data['Type']] : Bill::REFERENCE_TYPE_NAME_PAY[$data['Type']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 支出/收入 累计
     */
    public function totalAction()
    {
        $result = Bill::query()
            ->columns(['sum(Fee) as Total'])
            ->groupBy('OrganizationId')
            ->where('OrganizationId=:OrganizationId:')
            ->andWhere($this->request->get('Way') === 'Pay' ? 'Fee<0' : 'Fee>0')
            ->andWhere('Belong=:Belong:')
            ->bind(['OrganizationId' => $this->user->OrganizationId, 'Belong' => Bill::Belong_Organization])
            ->execute()->getFirst();
        $result = $result ?: ['Total' => 0];
        $this->response->setJsonContent($result);
    }

    public function historyAccountAction()
    {
        $histories = Trade::find([
            'columns'    => 'Gateway, Account, Name, Bank',
            'conditions' => 'OrganizationId=?0 and UserId=?1 and Status=?2 and Type=?3 and Belong=?4',
            'bind'       => [$this->user->OrganizationId, $this->user->Id, Trade::STATUS_COMPLETE, Trade::TYPE_ENCASH, Trade::Belong_Organization],
            'group'      => 'Gateway, Account, Name, Bank',
        ]);
        $this->response->setJsonContent($histories);
    }

    public function lastSuccessAccountAction()
    {
        $trade = Trade::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and Status=?2 and Type=?3 and Belong=?4',
            'bind'       => [$this->user->OrganizationId, $this->user->Id, Trade::STATUS_COMPLETE, Trade::TYPE_ENCASH, Trade::Belong_Organization],
            'order'      => 'Id desc',
        ]);
        return $this->response->setJsonContent($trade);
    }

    public function delBillAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        /** @var Bill $bill */
        $bill = Bill::findFirst([
            'conditions' => 'Id=?0 and OrganizationId=?1',
            'bind'       => [$this->request->getPut('Id', 'int'), $this->session->get('auth')['OrganizationId']],
        ]);
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$bill) {
                throw $exception;
            }
            $bill->IsDeleted = MessageLog::IsDeleted_Yes;
            if (!$bill->save()) {
                $exception->loadFromModel($bill);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 创建线下充值
     */
    public function createOfflinePayAction()
    {
        if (!$this->request->isPost()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $exception = new ParamException(Status::BadRequest);
        try {
            $offlinePay = new OfflinePay();
            $offlinePay->OrganizationId = $this->session->get('auth')['OrganizationId'];
            $offlinePay->Amount = $this->request->get('Amount');
            $offlinePay->AccountTitle = $this->request->get('AccountTitle');
            $offlinePay->Phone = $this->request->get('Phone');
            $offlinePay->Status = OfflinePay::STATUS_AUDIT;
            if (!$offlinePay->save()) {
                $exception->loadFromModel($offlinePay);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }
}
