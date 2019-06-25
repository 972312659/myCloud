<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:36
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Event;
use Phalcon\Paginator\Adapter\QueryBuilder;

class EventController extends Controller
{
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $event = new Event();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $event = Event::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$event) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($event->save($data) === false) {
                $exception->loadFromModel($event);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function deleteAction()
    {
        if (!$this->request->isDelete()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $event = Event::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
        if ($event) {
            $event->delete();
        }
    }

    public function listAction()
    {
        $query = Event::query();
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $this->request->get('PageSize') ?: 10,
                "page"    => $this->request->get('Page') ?: 1,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    public function readAction()
    {
        $event = Event::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
        if (!$event) {
            throw new ParamException(Status::BadRequest);
        }
        $this->response->setJsonContent($event);
    }
}