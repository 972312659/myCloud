<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/11
 * Time: 下午7:15
 */

namespace App\Controllers;


use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\combo\Read;
use App\Libs\combo\Verify;
use App\Libs\csv\FrontCsv;
use App\Libs\Push;
use App\Libs\Sphinx;
use App\Models\Combo;
use App\Models\ComboOrderBatch;
use App\Models\OrganizationCombo;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\UserEvent;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;
use App\Models\Organization;

class ComboController extends Controller
{
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('未登陆', Status::Unauthorized);
            }
            if ($this->request->isPost()) {
                $combo = new Combo();
                $data = $this->request->getPost();
                $data['OrganizationId'] = $auth['OrganizationId'];
                $data['CreateTime'] = time();
                $data['Status'] = 0;
                $data['Author'] = $auth['OrganizationName'];

                //验证
                $verify = new Verify($data);
                $data['Stock'] = $verify->create();

                if (empty($data['PassTime']) || !isset($data['PassTime']) || !is_numeric($data['PassTime'])) {
                    //过期时间为0时表示不过期
                    $data['PassTime'] = 0;
                }
                $data['Style'] = Combo::STYLE_ATONCE;
                $data['ReleaseTime'] = $data['CreateTime'];
                $data['Status'] = Combo::STATUS_ON;
                //不过期
                $data['PassTime'] = 0;
                //排序
                /** @var Combo $oldCombo */
                $oldCombo = Combo::findFirst([
                    'conditions' => 'OrganizationId=?0',
                    'bind'       => [$auth['OrganizationId']],
                    'order'      => 'IsTop desc',
                ]);
                $data['IsTop'] = 1;
                if ($oldCombo) {
                    if ($oldCombo->IsTop == 999) {
                        $data['IsTop'] = $oldCombo->IsTop;
                    } else {
                        $data['IsTop'] = $oldCombo->IsTop + 1;
                    }
                }

                if ($combo->save($data) === false) {
                    $exception->loadFromModel($combo);
                    throw $exception;
                }

                //推送消息
                $slaves = OrganizationRelationship::query()
                    ->columns(['O.Id as OrganizationId', 'U.Id as UserId', 'U.AppId', 'U.Phone', 'U.Factory', 'OU.Switch'])
                    ->leftJoin(Organization::class, 'O.Id=MinorId', 'O')
                    ->leftJoin(OrganizationUser::class, 'OU.OrganizationId=O.Id', 'OU')
                    ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
                    ->where(sprintf('MainId=%d', $auth['OrganizationId']))->andWhere('O.IsMain=2')->execute();
                MessageTemplate::send(
                    $this->queue,
                    $slaves,
                    MessageTemplate::METHOD_PUSH,
                    Push::TITLE_COMBO,
                    0,
                    0,
                    'combo_new_slave',
                    0
                );

                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($combo);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function updateAction($id)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                /** @var Combo $combo */
                $combo = Combo::findFirst([
                    'conditions' => 'Id=?0 and OrganizationId=?1',
                    'bind'       => [$id, $this->session->get('auth')['OrganizationId']],
                ]);
                if (!$combo) {
                    throw $exception;
                }

                unset($data['OffTime'], $data['Operator']);

                //验证
                $verify = new Verify($data);
                $data['Stock'] = $verify->create();

                //库存大于0才能上架
                if (isset($data['Status'])) {
                    if ($data['Status'] == Combo::STATUS_ON && $combo->Status == Combo::STATUS_OFF) {
                        if (isset($data['Stock']) && $data['Stock'] != null) {
                            if ($combo->Stock === 0 && $data['Stock'] == 0) {
                                throw new LogicException('库存大于0才能上架', Status::BadRequest);
                            }
                        } elseif (!isset($data['Stock'])) {
                            if ($combo->Stock === 0) {
                                throw new LogicException('库存大于0才能上架', Status::BadRequest);
                            }
                        }
                        $data['Operator'] = Combo::OPERATOR_SELF;
                    } elseif ($data['Status'] == Combo::STATUS_OFF) {
                        $data['OffTime'] = time();
                    }

                }

                if ($combo->save($data) === false) {
                    $exception->loadFromModel($combo);
                    throw $exception;
                }
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($combo);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 编辑库存、排序、上下架
     */
    public function updateListAction($id)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                /** @var Combo $combo */
                $combo = Combo::findFirst([
                    'conditions' => 'Id=?0 and OrganizationId=?1',
                    'bind'       => [$id, $this->session->get('auth')['OrganizationId']],
                ]);
                if (!$combo) {
                    throw $exception;
                }

                //验证库存
                if (isset($data['Stock'])) {
                    $combo->Stock = $data['Stock'];
                    if (!is_numeric($data['Stock']) || $data['Stock'] == 'null') {
                        $combo->Stock = null;
                    }
                }

                //排序
                if (isset($data['IsTop'])) {
                    if ($data['IsTop'] > 999) {
                        throw new LogicException('排序权重不得大于999', Status::BadRequest);
                    }
                    $combo->IsTop = $data['IsTop'];
                }

                //库存大于0才能上架
                if (isset($data['Status'])) {
                    if ($data['Status'] == Combo::STATUS_ON && $combo->Status == Combo::STATUS_OFF) {
                        if (isset($data['Stock']) && $data['Stock'] != null) {
                            if ($combo->Stock === 0 && $data['Stock'] == 0) {
                                throw new LogicException('库存大于0才能上架', Status::BadRequest);
                            }
                        } elseif (!isset($data['Stock'])) {
                            if ($combo->Stock === 0) {
                                throw new LogicException('库存大于0才能上架', Status::BadRequest);
                            }
                        }
                        $combo->Operator = Combo::OPERATOR_SELF;
                    } elseif ($data['Status'] == Combo::STATUS_OFF) {
                        $combo->OffTime = time();
                    }
                    $combo->Status = $data['Status'];
                }

                if ($combo->save() === false) {
                    $exception->loadFromModel($combo);
                    throw $exception;
                }
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($combo);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction($id)
    {
        $response = new Response();
        /** @var Combo $combo */
        $combo = Combo::findFirst(sprintf('Id=%d', $id));
        if (!$combo) {
            $response->setStatusCode(Status::BadRequest);
            return $response;
        }
        $read = new Read($combo);
        $response->setJsonContent($read->show());
        return $response;
    }

    public function listAction()
    {
        $organizationId = $this->session->get('auth')['OrganizationId'];
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $sql = "select a.Id,a.Name,a.Price,a.CreateTime,a.Stock,a.IsTop,a.Operator,a.Status,a.OffTime,(if(b.SalesQuantity is null,0,b.SalesQuantity)-if(c.BackQuantity is null,0,c.BackQuantity)) SalesQuantity from  Combo a 
left join (select ComboId,sum(QuantityBuy-QuantityBack) SalesQuantity from ComboOrderBatch where Status in (2,3) group by ComboId) b on b.ComboId=a.Id
left join (select n.ComboId,count(n.ComboId) BackQuantity from ComboOrder m left join ComboAndOrder n on n.ComboOrderId=m.Id where m.Status=5 group by n.ComboId) c on c.ComboId=a.Id
where a.OrganizationId={$organizationId} 
";

        //上下架状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            if ($data['Status'] == Combo::STATUS_ON) {
                $sql .= " and a.Status=1";
            } else {
                $sql .= " and a.Status!=1";
            }

        }
        $sql .= " order by a.IsTop desc,a.CreateTime desc";

        $paginator = new NativeArray([
            'data'  => $this->db->query($sql)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);
        $this->outputPagedJson($paginator);
    }

    public function deleteAction($id)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $combo = Combo::findFirst(sprintf('Id=%d', $id));
            if (!$combo) {
                throw $exception;
            }
            if ((int)$combo->OrganizationId !== (int)$this->user->OrganizationId) {
                throw new LogicException('无权操作', Status::Forbidden);
            }
            // $combo->delete();
            $this->response->setJsonContent(['message' => 'success']);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 自有套餐 首页显示需要传参数 Show=5
     */
    public function selfComboAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $hospitalId = $auth['HospitalId'];
        $data = $this->request->getPost();
        //处理套餐状态
        $now = time();
        if (Combo::findFirst(['conditions' => 'OrganizationId=?0 and ReleaseTime<=?1', 'bind' => [$hospitalId, $now]])) {
            Combo::changeStatus($hospitalId);
        }
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['Show']) && isset($data['Show'])) {
            $pageSize = $data['Show'];
        }
        $query = Combo::query()
            ->where(sprintf('OrganizationId=%d AND (PassTime=0 OR (PassTime>=%d AND ReleaseTime<=%d))', $hospitalId, time(), time()))
            ->orderBy('IsTop desc,CreateTime desc');
        //套餐名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'combo');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('Id', $ids);
            } else {
                $query->inWhere('Id', [-1]);
            }
        }
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
        $combos = $pages->items->toArray();

        if (!empty($data['Show']) && isset($data['Show'])) {
            $response->setJsonContent($combos);
        } else {
            //分页
            $result = [];
            $result['Data'] = $combos;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);
        }
        return $response;
    }

    /**
     * 首页共享套餐
     */
    public function shareComboAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $hospitalId = $auth['HospitalId'];
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['Show']) && isset($data['Show'])) {
            $pageSize = $data['Show'];
        }
        $query = $this->modelsManager->createBuilder()
            ->columns('C.Id,C.Name,C.OrganizationId,C.Intro,C.Price,C.Image,O.Name as HospitalName')
            ->addFrom(Combo::class, 'C')
            ->where(sprintf('OrganizationId != %d AND Share=2 AND Audit=1 AND (PassTime=0 OR (PassTime>=%d AND ReleaseTime<=%d))', $hospitalId, time(), time()))
            ->join(Organization::class, 'O.Id=C.OrganizationId', 'O', 'left')
            ->orderBy('OrganizationId');
        //套餐名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'combo');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('C.Id', $ids);
            } else {
                $query->inWhere('C.Id', [-1]);
            }
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
        $combos = $pages->items->toArray();
        if (!empty($data['Show']) && isset($data['Show'])) {
            $response->setJsonContent($combos);
        } else {
            //分页
            $result = [];
            $result['Data'] = $combos;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);
        }
        return $response;
    }

    /**
     * 全部套餐
     */
    public function allComboAction()
    {
        $hospitalId = $this->session->get('auth')['HospitalId'];
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['C.Id', 'C.Name', 'C.Price', 'C.Intro', 'C.Image', 'C.ReleaseTime', 'C.PassTime', 'C.OrganizationId as HospitalId', 'O.Name as HospitalName'])
            ->addFrom(Combo::class, 'C')
            ->join(Organization::class, 'O.Id=C.OrganizationId', 'O', 'left')
            ->where('C.Status=:Status:', ['Status' => Combo::STATUS_ON])
            ->andWhere("if(C.OrganizationId={$hospitalId},1=1,C.Share=2)")
            ->orderBy("C.OrganizationId<>{$hospitalId}");
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'combo');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('C.Id', $ids);
            } else {
                $query->inWhere('C.Id', [-1]);
            }
        }
        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 2.0网点端首页套餐列表
     */
    public function slaveAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        // Combo::deal($auth['HospitalId'], $this->sphinx);
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['O.Name as HospitalName', 'C.Id', 'C.Name as ComboName', 'C.Price', 'C.Intro', 'C.Image', 'C.Way', 'C.Amount', 'C.InvoicePrice'])
            ->addFrom(OrganizationCombo::class, 'A')
            ->leftJoin(Organization::class, 'O.Id=A.HospitalId', 'O')
            ->leftJoin(Combo::class, 'C.Id=A.ComboId', 'C')
            ->where('A.OrganizationId=:OrganizationId:', ['OrganizationId' => $auth['HospitalId']])
            ->andWhere('C.Status=:Status:', ['Status' => Combo::STATUS_ON]);
        //套餐名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'combo');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('C.Id', $ids);
            } else {
                $query->inWhere('C.Id', [-1]);
            }
        }
        $query->orderBy("A.HospitalId={$auth['HospitalId']} desc,C.IsTop desc,C.CreateTime desc");
        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 医院端套餐销售情况列表
     */
    public function comboOrderBatchAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $organizationId = $this->session->get('auth')['OrganizationId'];

        $phql = "select a.Id,a.Name,if(a.Status=1,'销售中','下架') StatusName,(if(d.SalesQuantity is null,0,d.SalesQuantity)-if(g.BackQuantity is null,0,g.BackQuantity)) SalesQuantity,if(h.Allot is null,0,h.Allot) Allot from Combo a
left join (select b.ComboId,sum(b.QuantityBuy-b.QuantityBack) SalesQuantity from ComboOrderBatch b left join Combo c on c.Id=b.ComboId where c.OrganizationId={$organizationId} and b.Status in (2,3) group by b.ComboId) d on d.ComboId=a.Id
left join (select e.ComboId,count(e.ComboId) Allot from ComboAndOrder e left join Combo f on f.Id=e.ComboId left join ComboOrder m on m.Id=e.ComboOrderId where f.OrganizationId={$organizationId} and m.Status in (2,3) group by e.ComboId) h on h.ComboId=a.Id
left join (select n.ComboId,count(n.ComboId) BackQuantity from ComboOrder m left join ComboAndOrder n on n.ComboOrderId=m.Id where m.Status=5 group by n.ComboId) g on g.ComboId=a.Id
where a.OrganizationId={$organizationId}
order by a.CreateTime desc
";

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
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 医院端套餐销售情况列表-详情
     */
    public function comboOrderBatchDetailsAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['O.Name as SendOrganizationName', 'SLU.Name as SlaveMan', 'SLU.Phone as SlaveManPhone', 'U.Name as Salesman', 'U.Phone as SalesmanPhone', 'B.QuantityBuy', 'B.CreateTime', 'B.QuantityUnAllot'])
            ->addFrom(ComboOrderBatch::class, 'B')
            ->leftJoin(Combo::class, 'C.Id=B.ComboId', 'C')
            ->leftJoin(OrganizationRelationship::class, 'R.MainId=B.HospitalId and R.MinorId=B.OrganizationId', 'R')
            ->leftJoin(Organization::class, 'O.Id=B.OrganizationId', 'O')
            ->leftJoin(OrganizationUser::class, 'OU.OrganizationId=B.OrganizationId', 'OU')
            ->leftJoin(User::class, 'U.Id=R.SalesmanId', 'U')
            ->leftJoin(User::class, 'SLU.Id=OU.UserId', 'SLU')
            ->inWhere('B.Status', [ComboOrderBatch::STATUS_WAIT_ALLOT, ComboOrderBatch::STATUS_USED])
            ->andWhere(sprintf('C.OrganizationId=%d', $this->session->get('auth')['OrganizationId']))
            ->andWhere('B.ComboId=:ComboId:', ['ComboId' => $data['Id']]);

        //网点名称
        if (isset($data['SendOrganizationName']) && !empty($data['SendOrganizationName'])) {
            $query->andWhere("O.Name=:SendOrganizationName:", ['SendOrganizationName' => $data['SendOrganizationName']]);
        }
        //业务经理
        if (isset($data['Salesman']) && !empty($data['Salesman'])) {
            $query->andWhere("U.Name=:Salesman:", ['Salesman' => $data['Salesman']]);
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("B.CreateTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('结束时间不能早于开始时间', Status::BadRequest);
            }
            $query->andWhere("B.CreateTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new FrontCsv($query);
            $csv->comboOrderBatchDetails();
        }

        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }
}