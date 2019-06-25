<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/4
 * Time: 下午8:54
 * 桃子互联网平台内部交易
 */

namespace App\Controllers;


use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\csv\FrontCsv;
use App\Libs\interiorTrade\Prepaid;
use App\Libs\interiorTrade\UnPass;
use App\Libs\Push;
use App\Libs\Sphinx;
use App\Models\Bill;
use App\Models\InteriorTrade;
use App\Models\InteriorTradeAndOrder;
use App\Models\InteriorTradeAndTransfer;
use App\Models\InteriorTradeLog;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\Transfer;
use App\Models\UserEvent;
use Phalcon\Db\RawValue;
use Phalcon\Mvc\Model\Query\Builder;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;

class InteriortradeController extends Controller
{
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $now = time();
            $auth = $this->session->get('auth');
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $interiorTrade = new InteriorTrade();
                $relation = OrganizationRelationship::findFirst([
                    'conditions' => 'MainId=?0 and MinorId=?1',
                    'bind'       => [$auth['OrganizationId'], $data['MinorId']],
                ]);
                if (!$relation) {
                    throw $exception;
                }
                $data['Created'] = time();
                $data['SendOrganizationId'] = $auth['OrganizationId'];
                $data['SendOrganizationName'] = $auth['OrganizationName'];
                $data['AcceptOrganizationId'] = $relation->MinorId;
                $data['AcceptOrganizationName'] = $relation->MinorName;
                $data['Status'] = InteriorTrade::STATUS_WAIT;
                $data['Style'] = InteriorTrade::STYLE_ACCOUNTS;
                $data['Amount'] = $data['Total'];
                $whiteList = ['SendOrganizationId', 'SendOrganizationName', 'AcceptOrganizationId', 'AcceptOrganizationName', 'Created', 'Status', 'Style', 'Message', 'Remark', 'Amount', 'Total'];
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $interiorTrade = InteriorTrade::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$interiorTrade || !in_array($auth['OrganizationId'], [$interiorTrade->SendOrganizationId, $interiorTrade->AcceptOrganizationId])) {
                    throw $exception;
                }
                if ($interiorTrade->Status === InteriorTrade::STATUS_UNPASS || $interiorTrade->Status === InteriorTrade::STATUS_PREPAID) {
                    throw new LogicException('无效的操作', Status::BadRequest);
                }
                if ($data['Status'] == InteriorTrade::STATUS_UNPASS && (!isset($data['Explain']) || empty($data['Explain']))) {
                    throw new LogicException('拒绝原因不能为空', Status::BadRequest);
                }
                $whiteList = ['Status', 'Explain'];
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($interiorTrade->save($data, $whiteList) === false) {
                $exception->loadFromModel($interiorTrade);
                throw $exception;
            }
            if ($interiorTrade->Style == InteriorTrade::STYLE_TRANSFER) {
                $transferId = InteriorTradeAndTransfer::findFirst(sprintf('InteriorTradeId=%d', $interiorTrade->Id))->TransferId;
            }
            if ($interiorTrade->Status == InteriorTrade::STATUS_UNPASS) {
                switch ($interiorTrade->Style) {
                    case InteriorTrade::STYLE_TRANSFER:
                        /** @var Transfer $transfer */
                        $transfer = Transfer::findFirst(sprintf('Id=%d', $transferId));
                        $transfer->Status = Transfer::NOTPAY;
                        if ($transfer->save() === false) {
                            $exception->loadFromModel($transfer);
                            throw $exception;
                        }
                        break;
                    case InteriorTrade::STYLE_PRODUCT:
                        $unPass = new UnPass($interiorTrade);
                        $unPass->product();
                }
            } elseif ($interiorTrade->Status == InteriorTrade::STATUS_PREPAID) {
                $prepaid = new Prepaid($interiorTrade, $auth, $now);
                switch ($interiorTrade->Style) {
                    case InteriorTrade::STYLE_TRANSFER:
                        $result = $prepaid->transfer($transferId);
                        $money_B = $result['money_B'];
                        $money_b = $result['money_b'];
                        $b_B = $result['b_B'];
                        $money_b_B = $result['money_b_B'];
                        $transfer = $result['transfer'];
                        break;
                    case InteriorTrade::STYLE_ACCOUNTS:
                        $result = $prepaid->accounts();
                        $hospital = $result['hospital'];
                        $slave = $result['slave'];
                        break;
                    case InteriorTrade::STYLE_PRODUCT:
                        $prepaid->product();
                        break;
                }
            }
            $this->db->commit();
            //发送消息
            if ($interiorTrade->Status == InteriorTrade::STATUS_PREPAID) {
                switch ($interiorTrade->Style) {
                    case InteriorTrade::STYLE_TRANSFER:
                        if ($money_B > 0) {
                            //发消息给小b
                            MessageTemplate::send(
                                $this->queue,
                                UserEvent::user((int)$transfer->SendOrganizationId),
                                MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                                Push::TITLE_TRANSFER,
                                0,
                                0,
                                'transfer_slave_check_out',
                                MessageLog::TYPE_TRANSFER,
                                $transfer->OrderNumber,
                                $transfer->PatientName,
                                Alipay::fen2yuan((int)$money_b)
                            );
                            //发消息给大B
                            MessageTemplate::send(
                                $this->queue,
                                null,
                                MessageTemplate::METHOD_MESSAGE,
                                Push::TITLE_TRANSFER,
                                (int)$transfer->AcceptOrganizationId,
                                MessageTemplate::EVENT_CKECK_OUT,
                                'transfer_major_check_out',
                                MessageLog::TYPE_TRANSFER,
                                $transfer->OrderNumber,
                                $transfer->PatientName,
                                Alipay::fen2yuan((int)$money_B)
                            );
                            //如果是共享，发送消息给小b的大B
                            if ($b_B) {
                                MessageTemplate::send(
                                    $this->queue,
                                    null,
                                    MessageTemplate::METHOD_MESSAGE,
                                    Push::TITLE_FUND,
                                    (int)$transfer->SendHospitalId,
                                    MessageTemplate::EVENT_CKECK_OUT,
                                    'transfer_share_check_out',
                                    MessageLog::TYPE_SHARE,
                                    $transfer->SendOrganizationName,
                                    Alipay::fen2yuan((int)$money_b_B)
                                );
                            }
                        }
                        $this->response->setStatusCode(Status::Created);
                        break;
                    case InteriorTrade::STYLE_ACCOUNTS:
                        //消息
                        if ($interiorTrade->Amount > 0) {
                            //发消息给小b
                            MessageTemplate::send(
                                $this->queue,
                                UserEvent::user((int)$slave->Id),
                                MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                                Push::TITLE_FUND,
                                0,
                                0,
                                'fund_accept',
                                MessageLog::TYPE_ACCOUNT_IN,
                                $interiorTrade->Message
                            );
                            //发消息给大B
                            MessageTemplate::send(
                                $this->queue,
                                null,
                                MessageTemplate::METHOD_MESSAGE,
                                Push::TITLE_FUND,
                                (int)$hospital->Id,
                                MessageTemplate::EVENT_CKECK_OUT,
                                'fund_send',
                                MessageLog::TYPE_ACCOUNT_OUT,
                                date('Y年m月d日H点i时s分', $now),
                                $slave->Name,
                                Alipay::fen2yuan((int)$interiorTrade->Amount)
                            );
                        }
                        break;
                    case InteriorTrade::STYLE_PRODUCT:
                        break;
                }
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($interiorTrade);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $auth = $this->session->get('auth');
        $columns = "I.Id,I.SendOrganizationId,I.SendOrganizationName,I.AcceptOrganizationId,I.AcceptOrganizationName,I.Created,I.Status,I.Style,I.Message,I.Remark,I.Explain,I.Total";
        if ($auth['OrganizationId'] === $auth['HospitalId']) {
            $columns_A = $columns . ",O.Name as MerchantName,O.MerchantCode,O.Contact,O.Phone,R.MinorName,T.PatientName";
            $sqlA = "select {$columns_A} from InteriorTrade I 
left join Organization O on O.Id=I.AcceptOrganizationId
left join OrganizationRelationship R on R.MinorId=O.Id and R.MainId={$auth['OrganizationId']}
left join InteriorTradeAndTransfer IT on IT.InteriorTradeId=I.Id
left join Transfer T on T.Id=IT.TransferId
where I.Style=1 and I.SendOrganizationId={$auth['OrganizationId']}
";
            $columns_B = $columns . ",O.Name as MerchantName,O.MerchantCode, O.Contact,O.Phone,R.MinorName,'' PatientName";
            $sqlB = "select {$columns_B} from InteriorTrade I 
left join Organization O on O.Id=I.AcceptOrganizationId
left join OrganizationRelationship R on R.MinorId=O.Id and R.MainId={$auth['OrganizationId']}
where I.Style!=1 and I.SendOrganizationId={$auth['OrganizationId']}
";


        } else {
            $sqlA = "select {$columns} from InteriorTrade I 
left join Organization O on O.Id=I.AcceptOrganizationId
where I.Style!=1 and I.AcceptOrganizationId={$auth['OrganizationId']}
";
            $sqlB = '';

        }

        $bindA = [];
        $bindB = [];

        //类型
        if (isset($data['Style']) && is_numeric($data['Style'])) {
            $sqlA .= ' and I.Style=?';
            $bindA[] = $data['Style'];
            if ($sqlB) {
                $sqlB .= ' and I.Style=?';
                $bindB[] = $data['Style'];
            }

        }
        //状态
        $role = $data['Role'];
        switch ($role) {
            case 'Finance'://财务
                if (!empty($data['Status']) && isset($data['Status']) && is_numeric($data['Status'])) {
                    $sqlA .= ' and I.Status=?';
                    $bindA[] = $data['Status'];
                    if ($sqlB) {
                        $sqlB .= ' and I.Status=?';
                        $bindB[] = $data['Status'];
                    }
                } else {
                    $sqlA .= ' and I.Status in (1,2,3)';
                    if ($sqlB) {
                        $sqlB .= ' and I.Status in (1,2,3)';
                    }
                }
                break;
            case 'Cashier'://出纳
                if (!empty($data['Status']) && isset($data['Status']) && is_numeric($data['Status'])) {
                    $sqlA .= ' and I.Status=?';
                    $bindA[] = $data['Status'];
                    if ($sqlB) {
                        $sqlB .= ' and I.Status=?';
                        $bindB[] = $data['Status'];
                    }
                } else {
                    $sqlA .= ' and I.Status in (2,3,4)';
                    if ($sqlB) {
                        $sqlB .= ' and I.Status in (2,3,4)';
                    }
                }
                break;
        }
        //机构号
        if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            $sqlA .= ' and O.MerchantCode=?';
            $bindA[] = $data['MerchantCode'];
            if ($sqlB) {
                $sqlB .= ' and O.MerchantCode=?';
                $bindB[] = $data['MerchantCode'];
            }
        }
        //商户名称
        if (!empty($data['MerchantName']) && isset($data['MerchantName'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $nameAlias = $sphinx->match($data['MerchantName'], 'alias')->fetchAll();
            $name = $sphinx->match($data['MerchantName'], 'name')->fetchAll();
            $ids = array_merge(array_column($nameAlias ? $nameAlias : [], 'id'), array_column($name ? $name : [], 'id'));
            if (count($ids)) {
                $ids = '(' . implode(',', $ids) . ')';
                $sqlA .= " and O.Id in {$ids}";
                if ($sqlB) {
                    $sqlB .= ' and O.Id in (2,3,4)';
                }
            } else {
                $sqlA .= ' and O.Id in (-1)';
                if ($sqlB) {
                    $sqlB .= ' and O.Id in (-1)';
                }
            }
        }
        //开始金额
        if (!empty($data['MinAmount']) && isset($data['MinAmount'])) {
            $sqlA .= ' and I.Total>=?';
            $bindA[] = $data['MinAmount'];
            if ($sqlB) {
                $sqlB .= ' and I.Total>=?';
                $bindB[] = $data['MinAmount'];
            }
        }
        //结束金额
        if (!empty($data['MaxAmount']) && isset($data['MaxAmount'])) {
            if (!empty($data['MaxAmount']) && !empty($data['MinAmount']) && ($data['MinAmount'] > $data['MaxAmount'])) {
                return $this->response->setStatusCode(Status::BadRequest);
            }
            $sqlA .= ' and I.Total<=?';
            $bindA[] = $data['MaxAmount'];
            if ($sqlB) {
                $sqlB .= ' and I.Total<=?';
                $bindB[] = $data['MaxAmount'];
            }
        }
        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $sqlA .= ' and I.Created>=?';
            $bindA[] = $data['StartTime'];
            if ($sqlB) {
                $sqlB .= ' and I.Created>=?';
                $bindB[] = $data['StartTime'];
            }
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                return $this->response->setStatusCode(Status::BadRequest);
            }
            $sqlA .= ' and I.Created<=?';
            $bindA[] = $data['EndTime'] + 86400;
            if ($sqlB) {
                $sqlB .= ' and I.Created<=?';
                $bindB[] = $data['EndTime'] + 86400;
            }
        }

        if ($sqlB) {
            $sql = '(' . $sqlA . ')' . ' union DISTINCT ' . '(' . $sqlB . ')' . '  order by Created desc';
        } else {
            $sql = $sqlA . ' order by I.Created desc';
        }
        $bind = array_merge($bindA, $bindB);
        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new FrontCsv(new Builder());
            $csv->interiorTrade($sql, $bind, $role ?: 'Cashier');
        }
        $paginator = new NativeArray([
            'data'  => $this->db->query($sql, $bind)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);

        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;
        foreach ($datas as &$data) {
            $data['StatusName'] = $data['Status'] == InteriorTrade::STATUS_PASS && $role == 'Cashier' ? '待结算' : InteriorTrade::STATUS_NAME[$data['Status']];
            $data['StyleName'] = InteriorTrade::STYLE_NAME[$data['Style']];
            if ($data['MinorName']) {
                $data['AcceptOrganizationName'] = $data['MinorName'];
                $data['MerchantName'] = $data['MinorName'];
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var InteriorTrade $interiorTrade */
            $interiorTrade = InteriorTrade::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$interiorTrade) {
                throw $exception;
            }
            $organization = Organization::findFirst(sprintf('Id=%d', $interiorTrade->AcceptOrganizationId));
            $result = $interiorTrade->toArray();
            /** @var OrganizationRelationship $organizationRelation */
            $organizationRelation = OrganizationRelationship::findFirst([
                'conditions' => 'MainId=?0 and MinorId=?1',
                'bind'       => [$interiorTrade->SendOrganizationId, $interiorTrade->AcceptOrganizationId],
            ]);
            if ($organizationRelation) $result['AcceptOrganizationName'] = $organizationRelation->MinorName;
            $result['Contact'] = $organization->Contact;
            $result['Phone'] = $organization->Phone;
            $result['MerchantCode'] = $organization->MerchantCode;
            $log = InteriorTradeLog::findFirst(['conditions' => 'InteriorTradeId=?0 and Status=?1', 'bind' => [$interiorTrade->Id, InteriorTrade::STATUS_WAIT]]);
            $result['UserName'] = $log->UserName;
            $result['ShareHospital'] = $interiorTrade->Total - ($interiorTrade->Amount + $interiorTrade->ShareCloud);
            $result['OrderNumber'] = 0;
            $result['ShareOne'] = 0;
            $result['Genre'] = 0;
            $result['GenreOne'] = 0;
            $result['Cost'] = 0;
            if ($interiorTrade->Style === InteriorTrade::STYLE_TRANSFER) {
                $transferId = InteriorTradeAndTransfer::findFirst(sprintf('InteriorTradeId=%d', $interiorTrade->Id))->TransferId;
                $transfer = Transfer::findFirst(sprintf('Id=%d', $transferId));
                if (!$transfer) {
                    throw $exception;
                }
                $result['Cost'] = $transfer->Cost;
                $result['OrderNumber'] = "{$transfer->OrderNumber}";
                $result['ShareOne'] = $transfer->ShareOne;
                $result['Genre'] = $transfer->Genre;
                $result['GenreOne'] = $transfer->GenreOne;
            }
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 通过InteriorTrade的Id获取详情Id
     */
    public function getAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var InteriorTrade $interiorTrade */
            $interiorTrade = InteriorTrade::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$interiorTrade) {
                throw $exception;
            }
            $id = null;
            switch ($interiorTrade->Style) {
                case InteriorTrade::STYLE_TRANSFER:
                    //转诊单
                    /** @var InteriorTradeAndTransfer $interiorTradeTransfer */
                    $interiorTradeTransfer = InteriorTradeAndTransfer::findFirst(sprintf('InteriorTradeId=%d', $interiorTrade->Id));
                    $id = $interiorTradeTransfer->TransferId;
                    break;
                case InteriorTrade::STYLE_PRODUCT:
                    //商城订单
                    /** @var InteriorTradeAndOrder $interiorTradeAndOrder */
                    $interiorTradeAndOrder = InteriorTradeAndOrder::findFirst(sprintf('InteriorTradeId=%d', $interiorTrade->Id));
                    $id = $interiorTradeAndOrder->OrderId;
                    break;
            }
            $this->response->setJsonContent(['Id' => $id]);
        } catch (ParamException $e) {
            throw $exception;
        }
    }
}
