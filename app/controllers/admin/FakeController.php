<?php

namespace App\Admin\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\AlipayOpen;
use App\Libs\AlipayTarget;
use App\Libs\fake\FakeAlipay;
use App\Libs\fake\models\Organization;
use App\Libs\fake\models\Trade;
use App\Libs\PaymentChannel\Alipay as ChannelAlipay;
use App\Models\OrganizationUser;
use App\Models\StaffTradeLog;
use App\Models\User;
use GuzzleHttp\Client;

class FakeController extends Controller
{
    public function rechargeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        $org_id = $this->request->getPost('org_id', 'absint');

        $sql = 'SELECT * FROM Organization WHERE IsMain = 1 AND Id IN (SELECT MainId FROM OrganizationRelationship 
        WHERE MinorId IN (SELECT Id FROM Organization WHERE Fake = 1 AND IsMain = 2)) AND Id = :id';
        $org = $this->db
            ->query($sql, [':id' => $org_id])
            ->fetch(\PDO::FETCH_OBJ);
        if (is_null($org)) {
            $exception->add('org_id', '充值机构错误');
            throw $exception;
        }

        $amount = $this->request->getPost('amount', 'absint');
        $now = time();

        try {
            $this->db->begin();

            $trade = new Trade();
            $trade->Gateway = Trade::GATEWAY_ALIPAY;
            $trade->Status = Trade::STATUS_PENDING;
            $trade->UserId = $this->staff->Id;
            $trade->Created = $now;
            $trade->Updated = $now;
            $trade->SerialNumber = $now << 32 | $this->staff->Id;
            $trade->OrganizationId = $org->Id;
            $trade->Amount = $amount;
            $trade->Type = Trade::TYPE_CHARGE;
            $trade->Fake = 1;

            if (!$trade->save()) {
                $exception->loadFromModel($trade);
                throw $exception;
            }

            $this->url->setBaseUri($this->request->getScheme().'://'.$this->request->getHttpHost());

            $alipay = new FakeAlipay();
            $alipay->trade_no = $trade->SerialNumber;
            $alipay->fee = $amount;
            $alipay->notify_url = $this->url->get('/payment/alipay');
            $alipay->subject = sprintf(
                '%s - %s 充值 %s 元',
                $org->Name,
                $this->staff->Name,
                Alipay::fen2yuan($amount)
            );

            $url = $alipay->receipt();

            $this->response->setJsonContent(['url' => $url]);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function organizationsAction()
    {
        $sql = 'SELECT * FROM Organization WHERE IsMain = 1 AND Id IN (SELECT MainId FROM OrganizationRelationship
        WHERE MinorId IN (SELECT Id FROM Organization WHERE Fake = 1 AND IsMain = 2))';

        //所有大B
        $hospitals = $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        $this->response->setJsonContent($hospitals);
    }

    public function childrenAction()
    {
        $id = $this->request->get('org_id');
        $page = $this->request->get('page', 'absint', 1);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $sql = 'SELECT * FROM Organization WHERE Id In(SELECT MinorId FROM OrganizationRelationship WHERE MainId = ?) AND Fake = 1 AND IsMain = 2 LIMIT ? OFFSET ?';

        $orgs = $this->db->fetchAll($sql, \PDO::FETCH_ASSOC, [$id, $limit, $offset]);

        $org_ids = array_column($orgs, 'Id');

        $costs = $this->costs($org_ids);

        foreach ($orgs as &$org) {
            $org['MoneyFake'] = isset($costs[$org['Id']]) ? (float)bcdiv($costs[$org['Id']], 100, 2) : 0;
        }

        $count = 'SELECT COUNT(*) AS total FROM Organization WHERE Id In(SELECT MinorId FROM OrganizationRelationship WHERE MainId = ?) AND Fake = 1 AND IsMain = 2';
        $res = $this->db->fetchOne($count, \PDO::FETCH_ASSOC, [$id]);

        $page_info = [
            'Page' => (int)$page,
            'PageSize' => $limit,
            'TotalPage' => ceil($res['total']/$limit),
            'Count' => $res['total']
        ];

        $this->response->setJsonContent([
            'PageInfo' => $page_info,
            'Data' => $orgs
        ]);
    }

    public function encashAction()
    {
        if ($this->request->isPost()) {
            $exception = new ParamException(Status::BadRequest);

            $org_id = $this->request->getPost('org_id');
            $channel = $this->channels->get(ChannelAlipay::Gateway);
            $account = $this->request->getPost('account');
            $name = $this->request->getPost('name');

            try {
                /**
                 * @var Organization $org
                 */
                $this->db->begin();
                // 扣除可用余额
                // 加速进度...暂时不验证这个机构的合法性
                $org = Organization::query()
                    ->where('Id = :id: AND Fake = 1 AND IsMain = 2')
                    ->bind([
                        'id' => $org_id
                    ])
                    ->execute()
                    ->getFirst();
                //验证最大可提现
                $cost = $this->cost($org_id);
                $max = $channel->getAvailable($cost);
                if ($max <= 0) {
                    throw new LogicException('无可提现余额', Status::BadRequest);
                }

                //所有tansfer都标记为已提现
                $sql = 'UPDATE Transfer SET IsEncash = 1 WHERE SendOrganizationId = :id AND IsFake = 1';
                $this->db->execute($sql, ['id' => $org_id]);

                $org_user = OrganizationUser::findFirst(['OrganizationId' => $org_id]);
                $user = User::findFirst($org_user->UserId);
                $now = time();
                // 产生交易单, 状态直接为完成
                $trade = new Trade();
                $trade->Gateway = ChannelAlipay::Gateway;
                $trade->Account = $account;
                $trade->UserId = $user->Id;
                $trade->Name = $name;
                $trade->Status = Trade::STATUS_COMPLETE;
                $trade->Created = $now;
                $trade->Updated = $now;
                $trade->SerialNumber = $now << 32 | $user->Id;
                $trade->OrganizationId = $org_id;
                $trade->Amount = $cost;
                $trade->Type = Trade::TYPE_ENCASH;
                $trade->Audit = 1;
                $trade->Fake = 1;
                if ($trade->save() === false) {
                    $exception->loadFromModel($trade);
                    throw $exception;
                }

                 //财务操作日志
                $reason = sprintf('%s - %s 提现 %s 元', $org->Name, $user->Name, Alipay::fen2yuan($cost));
                $tradeLog = new StaffTradeLog();
                $tradeLog->TradeId = $trade->Id;
                $tradeLog->StatusBefore = Trade::STATUS_BLANK;
                $tradeLog->StatusAfter = Trade::STATUS_PENDING;
                $tradeLog->StaffId = 15;
                $tradeLog->Created = $now;
                $tradeLog->Finance = 1;
                if ($tradeLog->save() === false) {
                    $exception->loadFromModel($tradeLog);
                    throw $exception;
                }

                $tradeLog = new StaffTradeLog();
                $tradeLog->TradeId = $trade->Id;
                $tradeLog->StatusBefore = Trade::STATUS_PENDING;
                $tradeLog->StatusAfter = Trade::STATUS_COMPLETE;
                $tradeLog->StaffId = 15;
                $tradeLog->Created = $now;
                $tradeLog->Finance = 2;
                if ($tradeLog->save() === false) {
                    $exception->loadFromModel($tradeLog);
                    throw $exception;
                }


                $this->response->setStatusCode(Status::OK);

                //生成支付宝链接
                $ret = new AlipayTarget();
                $ret->serialNo = $trade->SerialNumber;
                $ret->fee = $trade->Amount;
                $ret->account = APP_DEBUG ? 'daumjl8667@sandbox.com' : $trade->Account;
                $ret->remarks = $reason;
                $ret->name = $user->Name;
                $params = AlipayOpen::payment($ret);
                $client = new Client();
                $response = $client->get(AlipayOpen::GATEWAY . '?' . $params);
                $result = $channel->handleEncashResult(json_decode($response->getBody(), true));

                if (!$result->Success) {
                    $exception->add('提现失败', $result->Message);
                }

                $this->db->commit();
                return $this->response->setJsonContent($result);
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

    private function cost($org_id)
    {
        $sql = 'SELECT SUM(Cost*(ShareOne/100)) as cost FROM Transfer WHERE SendOrganizationId = :id AND IsEncash = 0 AND IsFake = 1';

        $res = $this->db
            ->query($sql, [
                'id' => $org_id
            ])
            ->fetch(\PDO::FETCH_ASSOC);
        return (float)$res['cost'];
    }

    private function costs(array $org_ids)
    {
        if (empty($org_ids)) {
            return [];
        }
        $sql = 'SELECT SUM(Cost*(ShareOne/100))  as cost, SendOrganizationId FROM Transfer WHERE SendOrganizationId IN (%s) AND IsEncash = 0 AND IsFake = 1 GROUP BY SendOrganizationId';

        $sql = sprintf($sql, implode(',', array_fill(0, count($org_ids), '?')));

        $res = $this->db->fetchAll($sql, \PDO::FETCH_ASSOC, $org_ids);
        $new_res = [];
        foreach ($res as $r) {
            $new_res[$r['SendOrganizationId']] = $r['cost'];
        }
        return $new_res;
    }
}
