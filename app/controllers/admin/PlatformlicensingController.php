<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/6/1
 * Time: 下午2:39
 * For: 服务费
 */

namespace App\Admin\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\PlatformLicensing;
use App\Models\PlatformLicensingLog;
use Phalcon\Paginator\Adapter\QueryBuilder;

class PlatformlicensingController extends Controller
{
    /**
     * 新建服务费
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $platformLicensing = new PlatformLicensing();
                $white = ['Name', 'Durations', 'Price', 'Created', 'Updated', 'Limited', 'Status', 'Amount'];
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $platformLicensing = PlatformLicensing::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$platformLicensing) {
                    throw $exception;
                }
                $white = ['Status'];
                $data['Status'] = $platformLicensing->Status ? PlatformLicensing::STATUS_OFF : PlatformLicensing::STATUS_ON;
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (isset($data['Status']) && is_numeric($data['Status'])) {
                $data['Status'] = (int)$data['Status'];
            }
            if ($platformLicensing->save($data, $white) === false) {
                $exception->loadFromModel($platformLicensing);
                throw $exception;
            }
            $platformLicensing->refresh();
            $this->response->setJsonContent($platformLicensing);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除服务费包
     */
    public function deleteAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $platformLicensing = PlatformLicensing::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
            if (!$platformLicensing) {
                throw $exception;
            }
            $platformLicensing->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 服务费包列表
     */
    public function listAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['P.Id', 'P.Name', 'P.Durations', 'P.Price', 'P.Created', 'P.Limited', 'P.Status', 'P.Amount', 'L.StaffName'])
            ->addFrom(PlatformLicensing::class, 'P')
            ->join(PlatformLicensingLog::class, 'L.PlatformLicensingId=P.Id', 'L', 'left');
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
        //启用状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere("P.Status=:Status:", ['Status' => $data['Status']]);
        }
        $query->orderBy('P.Id desc');
        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }
}