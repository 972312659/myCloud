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
use App\Libs\Sphinx;
use App\Models\Evaluate;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Transfer;
use App\Models\User;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;

class EvaluateController extends Controller
{
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录后操作', Status::Unauthorized);
            }
            if ($this->request->isPost()) {
                $evaluate = new Evaluate();
                $data = $this->request->getPost();
                $transfer = Transfer::findFirst(sprintf('Id=%d', $data['TransferId']));
                if (!$transfer) {
                    throw $exception;
                }
                if (Evaluate::findFirst(sprintf('TransferId=%d', $data['TransferId']))) {
                    $exception->add('Service', '不能重复提交');
                    throw $exception;
                }
                $data['CreateTime'] = time();
                $data['Status'] = 0;
                $data['UserId'] = 0;
                $data['ObserverId'] = $this->session->get('auth')['Id'];
                $data['OrganizationId'] = $transfer->AcceptOrganizationId;
                $whiteList = ['CreateTime', 'Status', 'OrganizationId', 'ObserverId', 'TransferId', 'Service', 'Environment', 'Doctor', 'DoctorDiscuss', 'UserId'];
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $data['UserId'] = $this->session->get('auth')['Id'];
                $data['AnswerTime'] = time();
                $data['Status'] = Evaluate::STATUS_REPLYED;
                $evaluate = Evaluate::findFirst(sprintf('TransferId=%d', $data['TransferId']));
                if (!$evaluate || !in_array($evaluate->OrganizationId, [$auth['OrganizationId'], $auth['HospitalId']])) {
                    throw $exception;
                }
                if ($evaluate->Status === Evaluate::STATUS_REPLYED) {
                    $exception->add('Answer', '不能重复评论');
                    throw $exception;
                }
                $whiteList = ['UserId', 'AnswerTime', 'Answer', 'Status'];
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($evaluate->save($data, $whiteList) === false) {
                $exception->loadFromModel($evaluate);
                throw $exception;
            }
            if ($this->request->isPost()) {
                /*医生评分开始*/
                $doctor = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$transfer->AcceptOrganizationId, $transfer->AcceptDoctorId],
                ]);
                $hospital = Organization::findFirst(sprintf('Id=%d', $transfer->AcceptOrganizationId));
                if (!$doctor || !$hospital) {
                    throw $exception;
                }
                $phql = "SELECT (SUM(Doctor)+5)/(COUNT(*)+1) as Score FROM Evaluate as e FORCE INDEX(`TransferId`)
LEFT JOIN Transfer as t  on t.Id=e.TransferId
WHERE t.AcceptDoctorId ={$transfer->AcceptDoctorId}";
                $doctor_score = $this->db->fetchOne($phql)['Score'];
                $phql = "SELECT (SUM(Doctor)+SUM(Service)+SUM(Environment)+15)/(3*COUNT(*)+3) as Score FROM Evaluate as e FORCE INDEX(`TransferId`)
LEFT JOIN Transfer as t  on t.Id=e.TransferId
WHERE t.AcceptOrganizationId ={$transfer->AcceptOrganizationId}";
                $hospital_score = $this->db->fetchOne($phql)['Score'];
                $doctor->Score = round($doctor_score, 1);
                $hospital->Score = round($hospital_score, 1);
                $transfer->IsEvaluate = 1;
                if ($doctor->save() === false) {
                    $exception->loadFromModel($doctor);
                    throw $exception;
                }
                if ($transfer->save() === false) {
                    $exception->loadFromModel($transfer);
                    throw $exception;
                }
                if ($hospital->save() === false) {
                    $exception->loadFromModel($hospital);
                    throw $exception;
                }
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($evaluate);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction()
    {
        $response = new Response();
        $evaluate = Evaluate::findFirst([
            'conditions' => 'TransferId=?0 and IsDeleted=?1',
            'bind'       => [$this->request->get('TransferId'), Evaluate::IsDeleted_No],
        ]);
        $response->setJsonContent($evaluate);
        return $response;
    }

    public function listAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder();
        $query->columns('E.Id,E.TransferId,E.Service,E.Environment,E.Doctor,E.DoctorDiscuss,E.Status,E.UserId,E.CreateTime,E.Answer,E.AnswerTime,E.OrganizationId,U.Name as UserName,O.Name as SlaveName,cast(T.OrderNumber as char) as OrderNumber');
        $query->addFrom(Evaluate::class, 'E')
            ->leftJoin(User::class, 'U.Id=E.UserId', 'U')
            ->leftJoin(Transfer::class, 'T.Id=E.TransferId', 'T')
            ->leftJoin(Organization::class, 'O.Id=T.SendOrganizationId', 'O')
            ->where("E.OrganizationId=:OrganizationId:", ['OrganizationId' => $auth['OrganizationId']])
            ->andWhere(sprintf('E.IsDeleted=%d', Evaluate::IsDeleted_No));
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
                $response->setStatusCode(Status::NotFound);
                return $response;
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
        $response->setJsonContent($result);
        return $response;
    }

    public function showListAction()
    {
        $response = new Response();
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder();
        $query->columns('E.Id,E.TransferId,E.Service,E.Environment,E.Doctor,E.DoctorDiscuss,E.Status,E.UserId,E.CreateTime,E.Answer,E.AnswerTime,E.OrganizationId,U.Image,T.SendOrganizationName');
        $query->addFrom(Evaluate::class, 'E');
        $query->where("E.OrganizationId=:OrganizationId:", ['OrganizationId' => $data['OrganizationId']])
            ->andWhere(sprintf('E.IsDeleted=%d', Evaluate::IsDeleted_No));
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
                $response->setStatusCode(Status::NotFound);
                return $response;
            }
            $query->andWhere("E.CreateTime<=:EndTime:", ['EndTime' => $data['EndTime']]);
        }
        //医生的评论
        if (!empty($data['AcceptDoctorId']) && isset($data['AcceptDoctorId'])) {
            $query->andWhere("T.AcceptDoctorId=:AcceptDoctorId:", ['AcceptDoctorId' => $data['AcceptDoctorId']]);
        }
        $query->join(Transfer::class, 'T.Id=E.TransferId', 'T', 'left');
        $query->join(OrganizationUser::class, 'U.UserId=E.ObserverId and U.OrganizationId=E.OrganizationId', 'U', 'left');
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
        $response->setJsonContent($result);
        return $response;
    }
}