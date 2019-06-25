<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:25
 */

namespace App\Admin\Controllers;

use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Models\Evaluate;
use App\Models\Organization;
use App\Models\Transfer;
use Phalcon\Paginator\Adapter\QueryBuilder;

class EvaluateController extends Controller
{
    /**
     * 评论列表
     */
    public function listAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder();
        $query->columns('E.Id,E.TransferId,E.Service,E.Environment,E.Doctor,E.DoctorDiscuss,E.Status,E.UserId,E.CreateTime,E.Answer,E.AnswerTime,E.OrganizationId,H.MerchantCode as HospitalMerchantCode,H.Name as HospitalName,S.MerchantCode as SlaveMerchantCode,S.Name as SlaveName');
        $query->addFrom(Evaluate::class, 'E');
        $query->join(Transfer::class, 'T.Id=E.TransferId', 'T', 'left');
        $query->join(Organization::class, 'H.Id=T.AcceptOrganizationId', 'H', 'left');
        $query->join(Organization::class, 'S.Id=T.SendOrganizationId', 'S', 'left');
        $query->where(sprintf('E.IsDeleted=%d', Evaluate::IsDeleted_No));
        //回复状态
        if (!empty($data['Status']) && isset($data['Status'])) {
            $query->andWhere("E.Status=:Status:", ['Status' => $data['Status']]);
        }
        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("E.CreateTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                return $this->response->setStatusCode(Status::BadRequest);
            }
            $query->andWhere("E.CreateTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        $query->orderBy('E.CreateTime desc');
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
            $evaluate = Evaluate::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$evaluate) {
                throw $exception;
            }
            $result = $evaluate->toArray();
            $result['HospitalName'] = $evaluate->Organization->Name;
            $result['SlaveName'] = $evaluate->Transfer->SendOrganization->Name;
            return $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function deleteAction()
    {
        if ($this->request->isDelete()) {
            /** @var Evaluate $evaluate */
            $evaluate = Evaluate::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
            if ($evaluate) {
                $evaluate->IsDeleted = Evaluate::IsDeleted_Yes;
                $exception = new ParamException(Status::BadRequest);
                try {
                    if (!$evaluate->save()) {
                        $exception->loadFromModel($evaluate);
                        throw $exception;
                    }
                } catch (ParamException $e) {
                    throw $e;
                }

            }
        }
    }
}