<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/26
 * Time: 上午10:26
 * Title: 账户资金管理
 */

namespace App\Admin\Controllers;

use App\Enums\BillTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\csv\AdminCsv;
use App\Libs\Sphinx;
use App\Models\Location;
use App\Models\OfflinePay;
use App\Models\OfflinePayImage;
use App\Models\OfflinePayLog;
use App\Models\Organization;
use App\Models\Bill;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\Staff;
use App\Models\StaffTradeLog;
use App\Models\Trade;
use App\Models\TradeLog;
use App\Models\User;
use Phalcon\Db\RawValue;
use Phalcon\Paginator\Adapter\QueryBuilder;

class AccountController extends Controller
{
    /**
     * 账户列表
     */
    public function listAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = Organization::query()
            ->columns('Id,Name,MerchantCode,Balance,Money,Type')
            ->where('Id!=:Id:')
            ->orderBy('Id desc');
        $bind['Id'] = Organization::PEACH;
        //商户号
        if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            $query->andWhere("MerchantCode=:MerchantCode:");
            $bind['MerchantCode'] = $data['MerchantCode'];
        }
        //商户类型
        if (!empty($data['Type']) && isset($data['Type']) && is_numeric($data['Type'])) {
            $query->andWhere("Type=:Type:");
            $bind['Type'] = $data['Type'];
        }
        //商户名称
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->andWhere('Id in ' . sprintf('(%s)', implode(',', $ids)));
            } else {
                $query->andWhere('Id=-1');
            }
        }
        $query->bind($bind);
        $paginate = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginate);
    }

    /**
     * 查看账户详情
     */
    public function readAction()
    {
        $organization = Organization::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
        if (!$organization) {
            return $this->response->setStatusCode(Status::BadRequest);
        }
        return $this->response->setJsonContent($organization);
    }

    /**
     * 交易流水（账单）
     */
    public function billListAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $columns = ['B.Id', 'B.Created', 'B.Type', 'B.Fee', 'B.Balance', 'B.Title', 'O.MerchantCode', 'O.Name', 'O.Type as OrganizationType', 'B.ReferenceType', 'B.ReferenceId'];
        $query = $this->modelsManager->createBuilder()
            ->addFrom(Bill::class, 'B')
            ->join(Organization::class, 'O.Id=B.OrganizationId', 'O', 'left')
            ->where("B.OrganizationId!=:OrganizationId:", ['OrganizationId' => Organization::PEACH]);
        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("B.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                return $this->response->setStatusCode(Status::BadRequest);
            }
            $query->andWhere("B.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        //流水号
        if (!empty($data['SerialNumber']) && isset($data['SerialNumber'])) {
            $columns[] = 'cast(T.SerialNumber as char) as SerialNumber';
            $query->join(Trade::class, "T.Id=B.ReferenceId", 'T', 'left');
            $query->andWhere('B.ReferenceType=:ReferenceType:', ['ReferenceType' => Bill::TYPE_ENCASH]);
            $query->andWhere("T.SerialNumber=:SerialNumber:", ['SerialNumber' => $data['SerialNumber']]);
        }
        //商户号
        if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            $query->andWhere("O.MerchantCode=:MerchantCode:", ['MerchantCode' => $data['MerchantCode']]);
        }
        //商户名称
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
        }
        $query->columns($columns);
        $query->orderBy('B.Created desc');
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
        if (!isset($data['SerialNumber']) || empty($data['SerialNumber'])) {
            $trade_ids = [];
            foreach ($datas as $data) {
                if ($data['ReferenceType'] == Bill::TYPE_ENCASH) {
                    $trade_ids[] = $data['ReferenceId'];
                }
            }
            $trades = Trade::query()->columns('Id,cast(SerialNumber as char) as SerialNumber')->inWhere('Id', $trade_ids)->execute()->toArray();
            $trades_new = [];
            if (count($trades)) {
                foreach ($trades as $trade) {
                    $trades_new[$trade['Id']] = (string)$trade['SerialNumber'];
                }
            }
            foreach ($datas as &$data) {
                $data['SerialNumber'] = $data['ReferenceType'] == Bill::TYPE_ENCASH ? $trades_new[$data['ReferenceId']] : '';
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 充值列表 Type=Trade::TYPE_CHARGE=1
     * 提现列表 Type=Trade::TYPE_ENCASH=2
     * 结算打款列表 Audit=Trade::AUDIT_PASS=1
     */
    public function tradeListAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $data = $this->request->get();
            $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
            $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
            $query = $this->modelsManager->createBuilder()
                ->addFrom(Trade::class, 'T')
                ->leftJoin(Organization::class, 'O.Id=T.OrganizationId', 'O')
                ->leftJoin(Organization::class, 'H.Id=T.HospitalId', 'H')
                ->leftJoin(OrganizationRelationship::class, 'S.MainId=T.HospitalId and S.MinorId=T.OrganizationId', 'S')
                ->leftJoin(User::class, 'U.Id=S.SalesmanId', 'U')
                ->orderBy('T.Created desc');
            $columns = 'O.Name,O.Contact,O.Phone,O.Type as OrganizationType,O.Balance,O.MerchantCode,T.Id,T.Gateway,T.Account,T.Bank,T.Name as OpName,cast(T.SerialNumber as char) as SerialNumber,T.Amount,T.Type,T.OrganizationId,T.UserId,T.Status,T.Created,T.Audit,T.Belong,H.Name as HospitalName,U.Name as Salesman,U.Phone as SalesmanPhone';
            //参数Type必传
            if (!empty($data['Type']) && isset($data['Type']) && is_numeric($data['Type'])) {
                $query->where('T.Type=:Type:', ['Type' => $data['Type']]);
                //提现
                if ($data['Type'] == Trade::TYPE_ENCASH) {
                    $query->leftJoin(Location::class, 'LP.Id=O.ProvinceId', 'LP');
                    $query->leftJoin(Location::class, 'LC.Id=O.CityId', 'LC');
                    $query->leftJoin(TradeLog::class, 'TL.TradeId=T.Id and TL.StatusAfter=' . Trade::STATUS_COMPLETE, 'TL');
                    $query->leftJoin(StaffTradeLog::class, 'SL.TradeId=T.Id and SL.StatusBefore=0 and SL.StatusAfter=1 and SL.Finance=' . StaffTradeLog::FINANCE_VERIFY, 'SL');
                    $columns .= ',LP.Name as Province,LC.Name as City,TL.Created as FinishTime,SL.Created as VerifyTime';
                }
            }
            //商户名
            if (!empty($data['Name']) && isset($data['Name'])) {
                $sphinx = new Sphinx($this->sphinx, 'organization');
                $name = $sphinx->match($data['Name'], 'name')->fetchAll();
                $ids = array_column($name ? $name : [], 'id');
                if (count($ids)) {
                    $query->inWhere('O.Id', $ids);
                } else {
                    $query->inWhere('O.Id', [-1]);
                }
            }
            //所属医院名称
            if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
                $sphinx = new Sphinx($this->sphinx, 'organization');
                $name = $sphinx->match($data['HospitalName'], 'name')->fetchAll();
                $ids = array_column($name ? $name : [], 'id');
                if (count($ids)) {
                    $query->inWhere('H.Id', $ids);
                } else {
                    $query->inWhere('H.Id', [-1]);
                }
            }
            //商户号
            if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
                $query->andWhere('O.MerchantCode=:MerchantCode:', ['MerchantCode' => $data['MerchantCode']]);
            }
            //联系人
            if (!empty($data['Contact']) && isset($data['Contact'])) {
                $query->andWhere('O.Contact=:Contact:', ['Contact' => $data['Contact']]);
            }
            //审核状态
            if (!empty($data['Status']) && isset($data['Status']) && is_numeric($data['Status'])) {
                $query->andWhere('T.Status=:Status:', ['Status' => $data['Status']]);
            }
            //流水号
            if (!empty($data['SerialNumber']) && isset($data['SerialNumber'])) {
                $query->andWhere('T.SerialNumber=:SerialNumber:', ['SerialNumber' => $data['SerialNumber']]);
            }
            //交易时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                $query->andWhere("T.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
            }
            if (!empty($data['EndTime']) && isset($data['EndTime'])) {
                if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                    throw $exception;
                }
                $query->andWhere("T.Created<=:EndTime:", ['EndTime' => $data['EndTime']]);
            }
            //商户类型
            if (!empty($data['OrganizationType']) && isset($data['OrganizationType']) && is_numeric($data['OrganizationType'])) {
                $query->andWhere('O.Type=:OrganizationType:', ['OrganizationType' => $data['OrganizationType']]);
            }
            //财务审核状态
            if (isset($data['Audit']) && is_numeric($data['Audit'])) {
                $query->andWhere('T.Audit=:Audit:', ['Audit' => $data['Audit']]);
            }
            $query->columns($columns);
            //导出表格
            if (isset($data['Export']) && is_numeric($data['Export'])) {
                $csv = new AdminCsv($query);
                $csv->trade();
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
            $list = $pages->items->toArray();
            if ($data['Type'] == Trade::TYPE_ENCASH) {
                for ($i = 0, $iMax = \count($list); $i < $iMax; $i++) {
                    $channel = $this->channels->get((int)$list[$i]['Gateway']);
                    $list[$i]['Fee'] = $channel->getFee((int)$list[$i]['Amount']);
                    $list[$i]['Balanced'] = $list[$i]['Balance'] - $channel->getTotal((int)$list[$i]['Amount']);
                }
            }
            $result = [];
            $result['Data'] = $list;
            $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 提现审核
     */
    public function verifyAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                /** @var Trade $trade */
                $trade = Trade::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
                $channel = $this->channels->get((int)$trade->Gateway);
                $now = time();
                if (!$trade) {
                    throw $exception;
                }
                $trade->Updated = $now;
                $statusBefore = $trade->Audit;
                $audit = $this->request->getPut('Audit', 'int');
                if ($audit == Trade::AUDIT_PASS) {
                    //财务审核通过,将Audit标记为已审核
                    $trade->Audit = Trade::AUDIT_PASS;
                } elseif ($audit == Trade::AUDIT_UNPASS) {
                    //财务审核不通过,将Audit标记为已审核
                    $trade->Status = Trade::STATUS_CLOSE;
                    $trade->Audit = Trade::AUDIT_UNPASS;

                    //是机构还是个人
                    if ($trade->Belong === Trade::Belong_Organization) {
                        $org = Organization::findFirst($trade->OrganizationId);
                    } else {
                        $org = OrganizationUser::findFirst([
                            'conditions' => 'OrganizationId=?0 and UserId=?1',
                            'bind'       => [$trade->OrganizationId, $trade->UserId],
                        ]);
                    }
                    // 审核不通过还原金额
                    $org->Money = new RawValue(sprintf('Money+%d', $channel->getTotal((int)$trade->Amount)));
                    if (!$org->save()) {
                        $exception->loadFromModel($org);
                        throw $exception;
                    }
                } else {
                    throw $exception;
                }
                if ($trade->save() === false) {
                    $exception->loadFromModel($trade);
                    throw $exception;
                }
                //员工操作记录
                $log = new StaffTradeLog();
                $log->TradeId = $trade->Id;
                $log->StaffId = $this->session->get('auth')['Id'];
                $log->StatusBefore = $statusBefore;
                $log->StatusAfter = $trade->Audit;
                $log->Created = $now;
                $log->Finance = StaffTradeLog::FINANCE_VERIFY;
                if ($log->save() === false) {
                    $exception->loadFromModel($log);
                    throw $exception;
                }
                //审核未通过记录TradeLog
                if ($audit == Trade::AUDIT_UNPASS) {
                    $tradeLog = new TradeLog();
                    $tradeLog->TradeId = $trade->Id;
                    $tradeLog->UserId = 0;
                    $tradeLog->StatusBefore = $statusBefore;
                    $tradeLog->StatusAfter = $trade->Status;
                    $tradeLog->Reason = '提现审核未通过';
                    $tradeLog->Created = $now;
                    if ($tradeLog->save() === false) {
                        $exception->loadFromModel($tradeLog);
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
     * 提现手续费
     */
    public function feeAction()
    {
        throw new LogicException('方法不再使用', Status::BadRequest);
    }

    /**
     * 商户银行卡
     */
    public function bankcardAction()
    {

    }

    /**
     * 查看Trade详情
     */
    public function readTradeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $trade = Trade::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$trade) {
                throw $exception;
            }
            $result = $trade->toArray();
            $result['Name'] = $trade->Organization->Name;
            $result['Contact'] = $trade->Organization->Contact;
            $result['ContactTel'] = $trade->Organization->ContactTel;
            $result['MerchantCode'] = $trade->Organization->MerchantCode;
            $result['OrganizationType'] = $trade->Organization->Type;
            $result['HospitalName'] = null;
            $result['Salesman'] = null;
            $result['SalesmanPhone'] = null;
            $relation = OrganizationRelationship::findFirst([
                'conditions' => 'MainId=?0 and MinorId=?1',
                'bind'       => [$trade->HospitalId, $trade->OrganizationId],
            ]);
            if ($relation) {
                $result['HospitalName'] = $relation->Main->Name;
                $salesman = User::findFirst(sprintf('Id=%d', $relation->SalesmanId));
                if ($salesman) {
                    $result['Salesman'] = $salesman->Name;
                    $result['SalesmanPhone'] = $salesman->Phone;
                }
            }
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 提现审核操作记录列表
     */
    public function logsAction()
    {
        $data = $this->request->get();
        $query = $this->modelsManager->createBuilder()
            ->columns('L.Id,L.TradeId,L.StaffId,L.StatusBefore,L.StatusAfter,L.Created,L.Finance,S.Name')
            ->addFrom(StaffTradeLog::class, 'L')
            ->join(Staff::class, 'S.Id=L.StaffId', 'S', 'left')
            ->where('L.TradeId=:TradeId:', ['TradeId' => $this->request->get('Id', 'int')]);
        if (!empty($data['Finance']) && isset($data['Finance']) && is_numeric($data['Finance'])) {
            $query->andWhere('L.Finance=:Finance:', ['Finance' => $data['Finance']]);
        }
        $logs = $query->getQuery()->execute();
        $this->response->setJsonContent($logs);
    }

    /**
     * 审计账户资金是否出错
     */
    public function auditAction()
    {
        $organizations = $this->modelsManager->createBuilder()
            ->columns(['sum(B.Fee) as Audit', 'O.Id', 'O.Name', 'O.Balance'])
            ->addFrom(Organization::class, 'O')
            ->join(Bill::class, 'B.OrganizationId=O.Id', 'B')
            ->groupBy('O.Id')
            ->having('Audit!=O.Balance')
            ->getQuery()->execute();
        $this->response->setJsonContent($organizations);
    }

    /**
     * 线下充值列表
     */
    public function offlinePayListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['O.Name as OrganizationName', 'O.MerchantCode', 'P.Id', 'P.Created', 'P.Phone', 'P.Amount', 'P.Status', 'P.AccountTitle'])
            ->addFrom(OfflinePay::class, 'P')
            ->leftJoin(Organization::class, 'O.Id=P.OrganizationId', 'O');
        //机构名称
        if (isset($data['Name']) && !empty($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
        }
        //创建时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("P.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $this->response->setStatusCode(Status::BadRequest);
                return;
            }
            $query->andWhere("P.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        //状态
        $payWay = $data['Way'] == 'Pay';
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere("P.Status=:Status:", ['Status' => $data['Status']]);
        } else {
            if ($payWay) {
                $query->inWhere('P.Status', [OfflinePay::STATUS_PASS, OfflinePay::STATUS_SUCCESS]);
            } else {
                $query->andWhere('P.Status!=:Status:', ['Status' => OfflinePay::STATUS_SUCCESS]);
            }
        }
        $query->orderBy('P.Created desc');
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
            if ($payWay && $data['Status'] == OfflinePay::STATUS_PASS) {
                $data['StatusName'] = '待充值';
            } else {
                $data['StatusName'] = OfflinePay::STATUS_NAME[$data['Status']];
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 读取一条线下充值记录
     */
    public function offlinePayReadAction()
    {
        $result = [];
        /** @var OfflinePay $offlinePay */
        $offlinePay = OfflinePay::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
        if ($offlinePay) {
            $logs = $this->modelsManager->createBuilder()
                ->columns(['L.LogTime', 'L.Status', 'S.Name as StaffName'])
                ->addFrom(OfflinePayLog::class, 'L')
                ->leftJoin(Staff::class, 'S.Id=L.StaffId', 'S')
                ->where(sprintf('L.OfflinePayId=%d', $offlinePay->Id))
                ->andWhere(sprintf('L.Status!=%d', OfflinePay::STATUS_AUDIT))
                ->orderBy('LogTime desc')
                ->getQuery()->execute()->toArray();
            $result['Logs'] = [];
            if (count($logs)) {
                foreach ($logs as $log) {
                    $str = date('Y-m-d H:i:s', $log['LogTime']) . ' ' . $log['StaffName'];
                    switch ($log['Status']) {
                        case OfflinePay::STATUS_PASS:
                            $str .= '审核通过';
                            break;
                        case OfflinePay::STATUS_FAILED:
                            $str .= '审核不通过';
                            break;
                        case OfflinePay::STATUS_CLOSED:
                            $str .= '关闭充值单';
                            break;
                        case OfflinePay::STATUS_SUCCESS:
                            $str .= '充值成功';
                            break;
                    }
                    $result['Logs'][] = $str;
                }
            }
            $result['Images'] = OfflinePayImage::find([
                'conditions' => 'OfflinePayId=?0',
                'bind'       => [$offlinePay->Id],
            ])->toArray();
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 线下充值审核
     */
    public function offlinePayAuditAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $status = $this->request->getPut('Status');
        if ($status != OfflinePay::STATUS_PASS && $status != OfflinePay::STATUS_FAILED) {
            throw new LogicException('状态数据错误', Status::BadRequest);
        }
        $images = [];
        if ($status == OfflinePay::STATUS_PASS) {
            $images = $this->request->getPut('Images');
            if (!is_array($images)) {
                throw new LogicException('数据错误', Status::BadRequest);
            } elseif (count($images) < 1 || count($images) > 9) {
                throw new LogicException('至少传一张，最多传九张凭证图片', Status::BadRequest);
            }
        }
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            /** @var OfflinePay $offlinePay */
            $offlinePay = OfflinePay::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
            if (!$offlinePay || $offlinePay != OfflinePay::STATUS_AUDIT) {
                throw $exception;
            }
            $offlinePay->Status = $status;
            if (!$offlinePay->save()) {
                $exception->loadFromModel($offlinePay);
                throw $exception;
            }
            $offlinePay->refresh();

            if ($offlinePay->Status === OfflinePay::STATUS_PASS) {
                foreach ($images as $image) {
                    /** @var OfflinePayImage $offlinePayImage */
                    $offlinePayImage = new OfflinePayImage();
                    $offlinePayImage->OfflinePayId = $offlinePay->Id;
                    $offlinePayImage->Image = $image;
                    if (!$offlinePayImage->save()) {
                        $exception->loadFromModel($offlinePayImage);
                        throw $exception;
                    }
                }
            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 线下充值成功
     */
    public function offlinePayFinishAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $status = $this->request->getPut('Status');
            if ($status != OfflinePay::STATUS_SUCCESS && $status != OfflinePay::STATUS_CLOSED) {
                throw new LogicException('状态数据错误', Status::BadRequest);
            }
            /** @var OfflinePay $offlinePay */
            $offlinePay = OfflinePay::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
            if (!$offlinePay) {
                throw $exception;
            }
            if ($offlinePay->Status !== OfflinePay::STATUS_PASS) {
                throw new LogicException('当前状态不能操作', Status::Forbidden);
            }
            $offlinePay->Status = $status;
            if (!$offlinePay->save()) {
                $exception->loadFromModel($offlinePay);
                throw $exception;
            }
            $offlinePay->refresh();
            //成功充值
            if ($offlinePay->Status = OfflinePay::STATUS_SUCCESS) {
                //机构账户变化
                /** @var Organization $organization */
                $organization = Organization::findFirst(sprintf('Id=%d', $offlinePay->OrganizationId));
                if (!$organization) {
                    throw $exception;
                }
                $organization->Money = new RawValue(sprintf('Money+%d', $offlinePay->Amount));
                $organization->Balance = new RawValue(sprintf('Balance+%d', $offlinePay->Amount));
                if (!$organization->save()) {
                    $exception->loadFromModel($organization);
                    throw $exception;
                }
                $organization->refresh();
                /** @var OfflinePayLog $log */
                $log = OfflinePayLog::findFirst([
                    'conditions' => 'OfflinePayId=?0 and Status=?1',
                    'bind'       => [$offlinePay->Id, OfflinePay::STATUS_AUDIT],
                ]);
                //生成流水账单
                $bill = new Bill();
                $bill->Title = sprintf(BillTitle::OfflinePay_Success, Alipay::fen2yuan($offlinePay->Amount));
                $bill->OrganizationId = $organization->Id;
                $bill->Fee = Bill::inCome($offlinePay->Amount);
                $bill->Balance = $organization->Balance;
                $bill->UserId = $log->UserId;
                $bill->Type = Bill::TYPE_PROFIT;
                $bill->Created = time();
                $bill->ReferenceType = Bill::REFERENCE_TYPE_OFFLINEPAY;
                $bill->ReferenceId = $offlinePay->Id;
                if ($bill->save() === false) {
                    $exception->loadFromModel($bill);
                    throw $exception;
                }
            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}