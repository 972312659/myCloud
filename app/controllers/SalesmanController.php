<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/2
 * Time: 4:24 PM
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\salesman\ReadBonus;
use App\Models\OrganizationUser;
use App\Models\SalesmanBonus;
use App\Models\SalesmanBonusRule;
use App\Models\SalesmanBonusRuleLog;
use App\Models\User;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SalesmanController extends Controller
{
    /**
     * 业务经理列表
     */
    public function listAction()
    {
        $results = $this->modelsManager->createBuilder()
            ->columns(['U.Id', 'U.Name', 'U.Phone', 'B.Id SalesmanBonusRuleId', 'B.IsFixed', 'B.Value'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->leftJoin(SalesmanBonusRule::class, 'B.OrganizationId=OU.OrganizationId and B.UserId=OU.UserId and Type=' . SalesmanBonusRule::Type_TransferCost, 'B')
            ->where(sprintf('OU.OrganizationId=%d', $this->session->get('auth')['OrganizationId']))
            ->andWhere(sprintf('OU.IsSalesman=%d', OrganizationUser::Is_Salesman_Yes))
            ->orderBy('OU.CreateTime asc')
            ->getQuery()->execute()->toArray();
        foreach ($results as &$result) {
            $result['Bonus'] = !$result['SalesmanBonusRuleId'] ? '未设置' : ($result['IsFixed'] == SalesmanBonusRule::IsFixed_Yes ? ($result['Value'] / 100) . '元' : ($result['Value'] / 100) . '%');
            $result['Value'] = $result['IsFixed'] == SalesmanBonus::IsFixed_Yes ? $result['Value'] : ($result['Value'] / 100);
        }
        $this->response->setJsonContent($results);
    }

    /**
     * 规则操作记录
     */
    public function logsAction()
    {
        $logs = SalesmanBonusRuleLog::query()
            ->columns(['SalesmanBonusRuleId', 'Describe', 'LogTime', 'U.Name'])
            ->leftJoin(User::class, 'U.Id=UserId', 'U')
            ->where(sprintf('OrganizationId=%d', $this->session->get('auth')['OrganizationId']))
            ->andWhere('SalesmanBonusRuleId=:SalesmanBonusRuleId:')
            ->bind(['SalesmanBonusRuleId' => $this->request->get('SalesmanBonusRuleId') ?: 0])
            ->orderBy('LogTime desc')
            ->execute();
        $this->response->setJsonContent($logs);
    }

    /**
     * 没有时创建规则，拥有时修改规则
     */
    public function updateBonusRuleAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::BadRequest);
            }
            $auth = $this->session->get('auth');
            $data = $this->request->getPost();
            /** @var SalesmanBonusRule $salesmanBonusRule */
            if (!isset($data['Id']) || !is_numeric($data['Id']) || $data['Id'] < 1) {
                $salesmanBonusRule = new SalesmanBonusRule();
            } else {
                $salesmanBonusRule = SalesmanBonusRule::findFirst([
                    'conditions' => 'Type=?0 and OrganizationId=?1 and UserId=?2 and Id=?3',
                    'bind'       => [SalesmanBonusRule::Type_TransferCost, $auth['OrganizationId'], $data['UserId'], $data['Id']],
                ]);
            }

            $salesmanBonusRule->OrganizationId = $auth['OrganizationId'];
            $salesmanBonusRule->IsFixed = (int)($data['IsFixed'] == SalesmanBonusRule::IsFixed_Yes ? SalesmanBonusRule::IsFixed_Yes : SalesmanBonusRule::IsFixed_No);
            $salesmanBonusRule->Value = $salesmanBonusRule->IsFixed == SalesmanBonusRule::IsFixed_Yes ? $data['Value'] : (int)floor(($data['Value'] * 10000) / 100);
            $salesmanBonusRule->UserId = (int)$data['UserId'];
            $salesmanBonusRule->Type = SalesmanBonusRule::Type_TransferCost;
            if ($salesmanBonusRule->save() === false) {
                $exception->loadFromModel($salesmanBonusRule);
                throw $exception;
            }
            $this->response->setJsonContent($salesmanBonusRule);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 奖励清单
     */
    public function bonusListAction()
    {
        $auth = $this->session->get('auth');
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = SalesmanBonus::query()
            ->where(sprintf('OrganizationId=%d', $auth['OrganizationId']))
            ->andWhere(sprintf('UserId=%d', $auth['Id']))
            ->andWhere(sprintf('Status=%d', SalesmanBonus::STATUS_CASHIER_PAYMENT));
        //按时间查询
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('结束时间不得早于开始时间', Status::BadRequest);
            }
            $query->andWhere("Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        $query->orderBy('Created desc');
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['ValueName'] = $data['IsFixed'] == SalesmanBonus::IsFixed_Yes ? $data['Value'] / 100 : ($data['Value'] / 100) . '%';
            $data['TypeName'] = SalesmanBonus::ReferenceType_Name[$data['ReferenceType']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 奖励详情
     */
    public function bonusReadAction()
    {
        $auth = $this->session->get('auth');
        /** @var SalesmanBonus $salesmanBonus */
        $salesmanBonus = SalesmanBonus::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and Id=?2 and Status=?3',
            'bind'       => [$auth['OrganizationId'], $auth['Id'], $this->request->get('Id'), SalesmanBonus::STATUS_CASHIER_PAYMENT],
        ]);
        if (!$salesmanBonus) {
            throw new LogicException('', Status::BadRequest);
        }
        $read = new ReadBonus($salesmanBonus);
        $this->response->setJsonContent($read->show());
    }
}