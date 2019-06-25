<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:34
 */

namespace App\Admin\Controllers;

use App\Enums\DoctorTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\csv\AdminCsv;
use App\Libs\transfer\Read;
use App\Models\Evaluate;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Transfer;
use App\Models\TransferLog;
use App\Models\TransferPicture;
use App\Models\User;
use Phalcon\Mvc\Model\Query\Builder;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;

class TransferorderController extends Controller
{
    /**
     * 转诊单列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $columns = 'T.Id,T.PatientName,T.PatientAge,T.PatientSex,T.PatientAddress,T.PatientId,T.PatientTel,T.SendHospitalId,T.SendOrganizationId,T.SendOrganizationName,T.TranStyle,T.AcceptOrganizationId,T.AcceptSectionId,T.AcceptDoctorId,T.AcceptSectionName,T.AcceptDoctorName,T.Disease,T.StartTime,T.ClinicTime,T.LeaveTime,T.EndTime,T.Status,T.OrderNumber,T.ShareOne,T.ShareTwo,T.ShareCloud,T.Remake,T.Genre,T.GenreOne,T.GenreTwo,T.Explain,T.Cost,T.CloudGenre,T.IsEvaluate,O.Phone as Come_Phone,O.Name as ComeName,O.MerchantCode as ComeMerchantCode,OA.MerchantCode as HospitalMerchantCode,OA.Name as HospitalName,U.Name as Sender';
        $query = "select {$columns} from Transfer T 
left join Organization O on O.Id=T.SendOrganizationId
left join Organization OA on OA.Id=T.AcceptOrganizationId
left join (select OrganizationId,min(UserId) UserId from OrganizationUser group by OrganizationId) OU on OU.OrganizationId=O.Id
left join User U on U.Id=OU.UserId
left join (select TransferId,min(LogTime) LogTime from TransferLog where Status=3 group by TransferId) TLA on TLA.TransferId=T.Id
left join (select TransferId,min(LogTime) LogTime from TransferLog where Status=4 group by TransferId) TLB on TLB.TransferId=T.Id 
where 1=1
";
        $bind = [];
        //搜索订单号
        if (!empty($data['OrderNumber']) && isset($data['OrderNumber'])) {
            $query .= ' and T.OrderNumber=?';
            $bind[] = $data['OrderNumber'];
        }
        //搜索状态
        $timeName = 'T.StartTime';
        if (!empty($data['Status']) && isset($data['Status'])) {
            $query .= ' and T.Status=?';
            $bind[] = $data['Status'];
            switch ($data['Status']) {
                case 2://发起
                    $timeName = 'T.StartTime';
                    break;
                case 3://接收
                    $timeName = 'TLA.LogTime';
                    break;
                case 4://拒绝
                    $timeName = 'TLB.LogTime';
                    break;
                case 5://治疗
                    $timeName = 'T.ClinicTime';
                    break;
                case 8://完结时间
                    $timeName = 'T.EndTime';
                    break;
                default:
                    //出院时间、结算未完成
                    $timeName = 'T.LeaveTime';
                    break;
            }
        }
        //诊单类型
        if (!empty($data['Genre']) && isset($data['Genre'])) {
            $query .= ' and T.Genre=?';
            $bind[] = $data['Genre'];
        }
        //申请时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query .= " and {$timeName}>=?";
            $bind[] = $data['StartTime'];
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                return $this->response->setStatusCode(Status::BadRequest);
            }
            $query .= " and {$timeName}<=?";
            $bind[] = $data['EndTime'] + 86400;
        }
        //商户名称
        if (!empty($data['Name']) && isset($data['Name'])) {
            $query .= ' and OA.Name=?';
            $bind[] = $data['Name'];
        }
        //商户号
        if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            $query .= ' and OA.MerchantCode=?';
            $bind[] = $data['MerchantCode'];
        }
        //已逻辑删除订单
        if (!empty($data['IsDeleted']) && is_numeric($data['IsDeleted'])) {
            $query .= ' and T.IsDeleted=?';
            $bind[] = $data['IsDeleted'];
        }
        $query .= " order by {$timeName} desc";
        if (isset($data['Export']) && !empty($data['Export'])) {
            //导出报表
            $csv = new AdminCsv(new Builder());
            $csv->transferOrder($query, $bind);
        }
        $paginator = new NativeArray(
            [
                "data"  => $this->db->query($query, $bind)->fetchAll(),
                "limit" => $pageSize,
                "page"  => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;
        foreach ($datas as &$data) {
            if ($data['Status'] === Transfer::FINISH) {
                $data['Share'] = ($data['GenreOne'] === 1 ? $data['Cost'] + $data['ShareOne'] : round($data['Cost'] * $data['ShareOne'] / 100, 2));
                $Two = $data['GenreTwo'] != 0 ? ($data['GenreTwo'] === 1 ? $data['Cost'] + $data['ShareTwo'] : round($data['Cost'] * $data['ShareTwo'] / 100, 2)) : 0;
                $Cloud = ($data['CloudGenre'] === 1 ? $data['Cost'] + $data['ShareCloud'] : round($data['Cost'] * $data['ShareCloud'] / 100, 2));
                $data['Other'] = $Two + $Cloud;
                $data['Pay'] = $data['Share'] + $data['Other'];
            }
            $data['OrderNumber'] = (string)$data['OrderNumber'];
            $data['GenreName'] = $data['Genre'] == 1 ? '自有转诊' : '共享转诊';
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 读取详情
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $id = $this->request->get('Id', 'int');
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst(sprintf('Id=%d', $id));
            if (!$transfer) {
                throw $exception;
            }
            $read = new Read($transfer);
            $this->response->setJsonContent($read->adminShow());
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 转诊图片
     */
    public function pictureAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $data = $this->request->get();
            $pictures = TransferPicture::query()
                ->columns('TransferId,Image,Type')
                ->where("TransferId=:TransferId:")
                ->andWhere("Type=:Type:")
                ->bind(["TransferId" => $data['TransferId'], "Type" => $data['Type']])
                ->execute();
            if (!$pictures) {
                throw $exception;
            }
            $this->response->setJsonContent($pictures);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 转诊日志
     */
    public function logsAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $transferId = $this->request->get('TransferId');
            $logs = TransferLog::find(sprintf('TransferId=%d', $transferId));
            if (!$logs) {
                throw $exception;
            }
            $this->response->setJsonContent($logs);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 转诊评论
     */
    public function evaluateAction()
    {
        $evaluate = Evaluate::findFirst([
            'conditions' => 'TransferId=?0 and IsDeleted=?1',
            'bind'       => [$this->request->get('TransferId', 'int'), Evaluate::IsDeleted_No],
        ]);
        if (!$evaluate) {
            $evaluate = [];
        }
        return $this->response->setJsonContent($evaluate);
    }

    /**
     * 批量删除已被医院逻辑删除的转诊单
     */
    public function batchDeleteAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $ids = $this->request->getPut('Ids');
            if (!is_array($ids)) {
                throw new LogicException('参数格式错误', Status::BadRequest);
            }
            if (empty($ids)) {
                return;
            }
            $this->db->begin();
            $transfers = Transfer::query()->inWhere('Id', $ids)->execute();
            $transfer_ids = array_column($transfers->toArray(), 'Id');
            $transfer_pictures = TransferPicture::query()->inWhere('TransferId', $transfer_ids)->execute();
            $transfer_logs = TransferLog::query()->inWhere('TransferId', $transfer_ids)->execute();
            $evaluates = Evaluate::query()->inWhere('TransferId', $transfer_ids)->execute();
            $deletes = array_column($transfers->toArray(), 'IsDeleted');
            if (in_array(Transfer::ISDELETED_NO, $deletes)) {
                throw $exception;
            }
            $evaluates->delete();
            $transfer_pictures->delete();
            $transfer_logs->delete();
            $transfers->delete();
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}
