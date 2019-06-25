<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/22
 * Time: 下午6:02
 */

namespace App\Controllers;

use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Event;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\UserEvent;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SmsController extends Controller
{
    /**
     * 事件订阅 批量操作
     */
    public function eventAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                $auth = $this->session->get('auth');
                if (!$auth) {
                    throw new LogicException('请登录', Status::Unauthorized);
                }
                $userIds = $this->request->getPut('UserIds');
                $eventId = $this->request->getPut('EventId');
                $acceptWays = $this->request->getPut('AcceptWays');
                $oldUsers = UserEvent::find([
                    'conditions' => 'OrganizationId=?0 and EventId=?1',
                    'bind'       => [$auth['OrganizationId'], $eventId],
                ]);
                if ($oldUsers) {
                    if ($oldUsers->delete() === false) {
                        $exception->add('EventId', '操作失败');
                    }
                }
                if (!empty($userIds) && isset($userIds) && !empty($eventId) && isset($eventId) && !empty($acceptWays) && isset($acceptWays) && is_array($acceptWays) && is_array($userIds)) {
                    $acceptWay = 0;
                    foreach ($acceptWays as $v) {
                        $acceptWay = $acceptWay | $v;
                    }
                    foreach ($userIds as $id) {
                        $event = new UserEvent();
                        $event->UserId = $id;
                        $event->EventId = $eventId;
                        $event->AcceptWay = $acceptWay;
                        $event->OrganizationId = $auth['OrganizationId'];
                        if ($event->save() === false) {
                            $exception->loadFromModel($event);
                            throw $exception;
                        }
                    }
                    $this->db->commit();

                } else {
                    throw $exception;
                }
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
     * 消息定制列表
     */
    public function eventListAction()
    {
        $event = Event::find()->toArray();
        $users = User::query()
            ->columns('Id,Name')
            ->join(OrganizationUser::class, 'OU.UserId=Id', 'OU')
            ->where('OU.OrganizationId=' . $this->user->OrganizationId)
            ->execute()->toArray();
        $userEvent = UserEvent::query()
            ->inWhere('UserId', array_column($users, 'Id'))
            ->andWhere(sprintf('OrganizationId=%d', $this->user->OrganizationId))
            ->execute()->toArray();
        $userEvent_new = [];
        foreach ($userEvent as $v) {
            $userEvent_new[$v['EventId']][] = $v['UserId'];
        }
        $type = [1 => '账户资金', 2 => '转诊单变化', 3 => '评价', 4 => '共享审核', 5 => '挂号'];
        foreach ($event as &$v) {
            $v['Count'] = count($userEvent_new[$v['Id']]);
            $v['AcceptWays'] = [['Name' => '消息', 'Value' => 1]];
            if ($v['Type'] == 1) {
                $v['AcceptWays'][] = ['Name' => '短信', 'Value' => 4];
            }
            //待分诊的地方加上短信
            if ($v['Type'] == 2) {
                if ($v['Name'] == '待分诊') {
                    $v['AcceptWays'][] = ['Name' => '短信', 'Value' => 4];
                }
            }
            $v['Type'] = $type[$v['Type']];
        }
        $this->response->setJsonContent($event);
    }

    /**
     * 当前消息事件的所有人员
     */
    public function userListAction()
    {
        if (!$this->user) {
            return $this->response->setStatusCode(Status::Unauthorized);
        }
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $type = $data['Type'];
        $users = $this->modelsManager->createBuilder();
        $users->columns('U.Id,U.Name,U.Phone,E.EventId,E.AcceptWay');
        $users->addFrom(OrganizationUser::class, 'OU');
        $users->join(User::class, 'U.Id=OU.UserId', 'U', 'left');
        $users->join(UserEvent::class, 'E.UserId=OU.UserId and E.OrganizationId=OU.OrganizationId', 'E', 'left');
        $users->where('OU.OrganizationId=:OrganizationId:', ['OrganizationId' => $this->user->OrganizationId]);
        $users->andWhere('E.EventId=:EventId:', ['EventId' => $data['EventId']]);
        $paginate = new QueryBuilder([
            'builder' => $users,
            'limit'   => $pageSize,
            'page'    => $page,
        ]);
        $pages = $paginate->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['AcceptWays'] = [];
            if ($data['AcceptWay'] & MessageTemplate::METHOD_MESSAGE) {
                $data['AcceptWays'][] = '消息';
            }
            if ($data['AcceptWay'] & MessageTemplate::METHOD_PUSH) {
                $data['AcceptWays'][] = MessageTemplate::METHOD_PUSH;
            }
            if ($data['AcceptWay'] & MessageTemplate::METHOD_SMS) {
                $data['AcceptWays'][] = '短信';
            }
        }
        if (!empty($type) && isset($type)) {
            if ($type === 'notIn') {
                $query = User::query()->join('App\Models\OrganizationUser', 'OU.UserId=Id', 'OU');
                $query->columns('Id,Name,Phone');
                if (!empty($datas)) {
                    $query->notInWhere('Id', array_column($datas, 'Id'));
                }
                $query->andWhere('OU.OrganizationId=' . $this->user->OrganizationId);
                $datas = $query->execute();
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 修改个人的该事件 删除该个人的事件
     */
    public function editAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录后操作', Status::Unauthorized);
            }
            $userId = $this->request->getPut('UserId');
            $eventId = $this->request->getPut('EventId');
            $acceptWays = $this->request->getPut('AcceptWays');
            $userEvent = UserEvent::findFirst(['UserId=?0 and EventId=?1 and OrganizationId=?2', 'bind' => [$userId, $eventId, $auth['OrganizationId']]]);
            if ($this->request->isPut()) {
                if (!$userEvent) {
                    throw $exception;
                }
                $acceptWay = 0;
                if (!empty($acceptWays) && isset($acceptWays) && is_array($acceptWays)) {
                    foreach ($acceptWays as $v) {
                        $acceptWay = $acceptWay | $v;
                    }
                }
                $userEvent->AcceptWay = $acceptWay;
                if ($userEvent->save() === false) {
                    $exception->loadFromModel($userEvent);
                    throw $exception;
                }
            } elseif ($this->request->isDelete()) {
                if ($userEvent->delete() === false) {
                    throw new LogicException('服务器异常，请稍后再试', Status::InternalServerError);
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}