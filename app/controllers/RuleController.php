<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/19
 * Time: 下午4:00
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sort;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\ProfitGroup;
use App\Models\ProfitRule;
use App\Models\RuleOfShare;
use App\Models\Section;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;

class RuleController extends Controller
{
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $rule = new RuleOfShare();
                $data = $this->request->getPost();
                $data['OrganizationId'] = $this->session->get('auth')['OrganizationId'];
                $data['CreateOrganizationId'] = $data['OrganizationId'];
                $data['Style'] = RuleOfShare::STYLE_HOSPITAL;
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $data['UpdateTime'] = time();
                $rule = RuleOfShare::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$rule || (int)$rule->CreateOrganizationId !== (int)$this->user->OrganizationId) {
                    throw $exception;
                }
                if ($rule->Style == RuleOfShare::STYLE_PLATFORM) {
                    $exception->add('Ratio', '无权操作');
                    throw $exception;
                }
                unset($data['Style']);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (isset($data['Ratio']) && !empty($data['Ratio'])) {
                if (!is_numeric($data['Ratio'])) {
                    $exception->add('Ratio', '结算请填写数字（例如:10）');
                    throw $exception;
                }
            }
            //设置验证场景
            $rule->setScene(RuleOfShare::SCENE_RULE_CREATE);
            if ($rule->save($data) === false) {
                $exception->loadFromModel($rule);
                throw $exception;
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($rule);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction($id)
    {
        $response = new Response();
        if (!$rule = RuleOfShare::findFirst(sprintf('Id=%d', $id))) {
            $response->setStatusCode(Status::NotFound);
            return $response;
        }
        $response->setJsonContent($rule);
        return $response;
    }

    public function listAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = RuleOfShare::query()
            ->where("OrganizationId=:OrganizationId:")
            ->andWhere("Style=:Style:");
        $bind['OrganizationId'] = $auth['HospitalId'];
        $bind['Style'] = RuleOfShare::STYLE_HOSPITAL;
        if (!empty($data['Name']) && isset($data['Name'])) {
            $query->andWhere("Name=:Name:");
            $bind['Name'] = $data['Name'];
        }
        $query->bind($bind);
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
        $rule = OrganizationRelationship::query()
            ->columns('RuleId')
            ->where(sprintf('MainId=%d', $auth['HospitalId']))
            ->execute()
            ->toArray();
        $rule_new = [];
        foreach ($rule as $v) {
            $rule_new[] = $v['RuleId'];
        }
        $rule_count = array_count_values($rule_new);
        foreach ($datas as &$data) {
            $data['Members'] = $rule_count[$data['Id']] ? $rule_count[$data['Id']] : 0;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    public function deleteAction($id)
    {
        $response = new Response();
        if ($this->request->isDelete()) {
            $rule = RuleOfShare::findFirst(sprintf('Id=%d', $id));
            if (!$rule || (int)$rule->CreateOrganizationId !== (int)$this->user->OrganizationId) {
                $response->setStatusCode(Status::BadRequest);
                return $response;
            }
            if (count(OrganizationRelationship::find(['RuleId=?0', 'bind' => [$rule->Id]])->toArray())) {
                $response->setStatusCode(Status::BadRequest);
                $response->setJsonContent(['message' => '分组内有成员，不能删除']);
                return $response;
            }
            $rule->delete();
            $response->setJsonContent(['message' => 'success']);
            return $response;
        }
        $response->setStatusCode(Status::MethodNotAllowed);
        return $response;
    }

    /**
     * 显示规则下所有网点名称
     */
    public function minorsAction()
    {
        $ruleId = $this->request->get('RuleId', 'int');
        $minors = OrganizationRelationship::find([
            'MainId=?0 and RuleId=?1',
            'bind' => [$this->user->OrganizationId, $ruleId],
        ]);
        $this->response->setJsonContent($minors);
    }

    /**
     * 批量修改网点规则
     */
    public function editAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                $ids = $this->request->getPut('Ids');
                $ruleId = $this->request->getPut('RuleId');
                $rule = RuleOfShare::findFirst(sprintf('Id=%d', $ruleId));
                if (!$rule || (int)$rule->CreateOrganizationId != (int)$this->user->OrganizationId) {
                    throw $exception;
                }
                if ($rule->Style == RuleOfShare::STYLE_PLATFORM) {
                    $exception->add('RuleId', '无权操作');
                    throw $exception;
                }
                $mainId = $this->user->OrganizationId;
                if (!is_array($ids)) {
                    throw $exception;
                }
                $minors = OrganizationRelationship::query()
                    ->inWhere('MinorId', $ids)
                    ->andWhere('MainId=' . $mainId)
                    ->execute();
                foreach ($minors as $minor) {
                    $minor->RuleId = (int)$ruleId;
                    if ($minor->save() === false) {
                        $exception->loadFromModel($minor);
                        throw $exception;
                    }
                }
                $this->db->commit();
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 新建，修改分润组
     */
    public function groupAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $organizationId = $this->session->get('auth')['OrganizationId'];
            if (!$organizationId) {
                throw new LogicException('未登录', Status::Unauthorized);
            }
            if ($this->request->isPost()) {
                $group = new ProfitGroup();
                $group->Name = $this->request->getPost('Name');
                $group->OrganizationId = $organizationId;
            } elseif ($this->request->isPut()) {
                $group = ProfitGroup::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
                if (!$group || $group->OrganizationId != $organizationId) {
                    throw $exception;
                }
                $group->Name = $this->request->getPut('Name');
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!$group->save()) {
                $exception->loadFromModel($group);
                throw $exception;
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($group);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除分组
     */
    public function delGroupAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $organizationId = $this->session->get('auth')['OrganizationId'];
            if (!$organizationId) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $group = ProfitGroup::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
            if (!$group || $group->OrganizationId != $organizationId) {
                throw $exception;
            }
            $count = $this->modelsManager->createBuilder()
                ->columns('count(*) count')
                ->addFrom(OrganizationRelationship::class, 'R')
                ->leftJoin(Organization::class, 'O.Id=R.MinorId', 'O')
                ->where(sprintf('R.MainId=%d', $organizationId))
                ->andWhere(sprintf('O.IsMain=%d', Organization::ISMAIN_SLAVE))
                ->andWhere('R.RuleId=:RuleId:', ['RuleId' => $group->Id])
                ->limit(1)->getQuery()->execute()[0];
            if ($count->count > 0) {
                throw new LogicException('请先将该组的网点清空后再删除', Status::BadRequest);
            }
            $group->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 批量修改网点分组
     */
    public function editGroupAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                $ids = $this->request->getPut('Ids');
                $groupId = $this->request->getPut('GroupId');
                $group = ProfitGroup::findFirst(sprintf('Id=%d', $groupId));
                $mainId = $this->user->OrganizationId;
                if (!$group || (int)$group->CreateOrganizationId != (int)$mainId) {
                    throw $exception;
                }
                if (!is_array($ids)) {
                    throw $exception;
                }
                $minors = OrganizationRelationship::query()
                    ->inWhere('MinorId', $ids)
                    ->andWhere('MainId=' . $mainId)
                    ->execute();
                foreach ($minors as $minor) {
                    $minor->RuleId = $group->Id;
                    if ($minor->save() === false) {
                        $exception->loadFromModel($minor);
                        throw $exception;
                    }
                }
                $this->db->commit();
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 分润组列表
     */
    public function groupListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $organizationId = $this->session->get('auth')['OrganizationId'];
        $phql = "select P.Id,P.Name,if(R.Count is null,0,R.Count) Count from ProfitGroup P left join (select a.RuleId,count(a.MinorId) as count from OrganizationRelationship a left join Organization b on b.Id=a.MinorId where a.MainId={$organizationId} and b.IsMain=2 GROUP BY RuleId) R on R.RuleId=P.Id where OrganizationId={$organizationId} order by Id desc";
        $paginator = new NativeArray([
            'data'  => $this->db->query($phql)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 配置分润规则
     */
    public function profitAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $organizationId = $this->session->get('auth')['OrganizationId'];
            if (!$organizationId) {
                throw new LogicException('未登录', Status::Unauthorized);
            }
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $profitRule = new ProfitRule();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $profitRule = ProfitRule::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$profitRule || $profitRule->OrganizationId != $organizationId) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $arr = ['MaxAmount', 'EndTime', 'SectionId', 'GroupId', 'OutpatientOrInpatient'];
            foreach ($arr as $item) {
                if (!isset($data[$item]) || (!is_numeric($data[$item]) && $data[$item])) {
                    unset($data[$item]);
                } elseif (!$data[$item]) {
                    $data[$item] = null;
                }
            }
            if (!isset($data['Priority']) || empty($data['Priority']) || !is_numeric($data['Priority'])) {
                throw new LogicException('排序不能为空', Status::BadRequest);
            }
            //验证当前分润组里的排序是否存在
            $exist = ProfitRule::findFirst([
                'conditions' => 'OrganizationId=?0 and Priority=?1',
                'bind'       => [$organizationId, $data['Priority']],
            ]);
            if ($this->request->isPost()) {
                if ($exist) {
                    throw new LogicException('该排序数值已被占用', Status::BadRequest);
                }
            } else {
                if ($data['Priority'] != $profitRule->Priority) {
                    if ($exist) {
                        throw new LogicException('该排序数值已被占用', Status::BadRequest);
                    }
                }
            }
            $data['OrganizationId'] = $organizationId;
            if (!$profitRule->save($data)) {
                $exception->loadFromModel($profitRule);
                throw $exception;
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($profitRule);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 新分润规则列表
     */
    public function profitListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns([
                'R.Id', 'R.MinAmount', 'R.MaxAmount', 'R.SectionId', 'R.GroupId', 'R.BeginTime', 'R.EndTime', 'R.IsFixed', 'R.Value', 'R.Priority', 'R.OutpatientOrInpatient',
                'G.Name as GroupName', 'S.Name as SectionName',
            ])
            ->addFrom(ProfitRule::class, 'R')
            ->leftJoin(ProfitGroup::class, 'G.Id=R.GroupId', 'G')
            ->leftJoin(Section::class, 'S.Id=R.SectionId', 'S')
            ->where(sprintf('R.OrganizationId=%d', $this->user->OrganizationId));
        //科室
        if (isset($data['SectionId']) && is_numeric($data['SectionId'])) {
            $query->inWhere('R.SectionId', [$data['SectionId'], null]);
        } elseif (isset($data['SectionId']) && $data['SectionId'] == 'null') {
            $query->andWhere('R.SectionId is null');
        }
        //分润组
        if (isset($data['GroupId']) && is_numeric($data['GroupId'])) {
            $query->inWhere('R.GroupId', [$data['GroupId'], null]);
        } elseif (isset($data['GroupId']) && $data['GroupId'] == 'null') {
            $query->andWhere('R.GroupId is null');
        }
        //门诊或者住院
        if (isset($data['OutpatientOrInpatient']) && is_numeric($data['OutpatientOrInpatient'])) {
            $query->inWhere('R.OutpatientOrInpatient', [$data['OutpatientOrInpatient'], null]);
        } elseif (isset($data['OutpatientOrInpatient']) && $data['OutpatientOrInpatient'] == 'null') {
            $query->andWhere('R.OutpatientOrInpatient is null');
        }
        //金额
        if (isset($data['MinAmount']) && is_numeric($data['MinAmount'])) {
            $query->andWhere("R.MinAmount>=:MinAmount:", ['MinAmount' => $data['MinAmount']]);
        }
        if (isset($data['MaxAmount']) && is_numeric($data['MaxAmount'])) {
            if (!empty($data['MinAmount']) && !empty($data['MaxAmount']) && ($data['MinAmount'] > $data['MaxAmount'])) {
                throw new LogicException('最小金额大于最大金额', Status::BadRequest);
            }
            $query->andWhere("R.MaxAmount<=:MaxAmount:", ['MaxAmount' => $data['MaxAmount']]);
        }
        //分润方式
        if (isset($data['IsFixed']) && !empty($data['IsFixed'])) {
            $query->andWhere('R.IsFixed=:IsFixed:', ['IsFixed' => $data['IsFixed']]);
        }
        //分润数值
        if (isset($data['MinValue']) && is_numeric($data['MinValue'])) {
            $query->andWhere("R.Value>=:MinValue:", ['MinValue' => $data['MinValue']]);
        }
        if (isset($data['MaxValue']) && is_numeric($data['MaxValue'])) {
            if (!empty($data['MinValue']) && !empty($data['MaxValue']) && ($data['MinValue'] > $data['MaxValue'])) {
                throw new LogicException('最小金额大于最大金额', Status::BadRequest);
            }
            $query->andWhere("R.Value<=:MaxValue:", ['MaxValue' => $data['MaxValue']]);
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("R.BeginTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('起始时间大于结束时间', Status::BadRequest);
            }
            $query->andWhere("R.EndTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        $query->orderBy('Priority asc');
        $paginator = new QueryBuilder([
            'builder' => $query,
            'limit'   => $pageSize,
            'page'    => $page,
        ]);
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['MaxAmount'] = $data['MaxAmount'] ?: '以上';
            $data['Time'] = date('Y.m.d', $data['BeginTime']) . '-' . ($data['EndTime'] ? date('Y.m.d', $data['EndTime']) : '永久');
            $data['SectionName'] = $data['SectionName'] ?: '全部科室';
            $data['GroupName'] = $data['GroupName'] ?: '全部分组';
            $data['OutpatientOrInpatientName'] = ProfitRule::OutpatientOrInpatientName[$data['OutpatientOrInpatient']];
            $data['IsFixedName'] = ProfitRule::IsFixedName[$data['IsFixed']];
            $data['GroupId'] = $data['GroupId'] ?: '';
            $data['SectionId'] = $data['SectionId'] ?: '';
            $data['OutpatientOrInpatient'] = $data['OutpatientOrInpatient'] ?: '';
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 删除新分润规则
     */
    public function delProfitAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $profitRule = ProfitRule::findFirst([
                'conditions' => 'Id=?0 and OrganizationId=?1',
                'bind'       => [$this->request->getPut('Id'), $this->user->OrganizationId],
            ]);
            if (!$profitRule) {
                throw $exception;
            }
            $profitRule->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}