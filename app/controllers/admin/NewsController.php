<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2018/6/26
 * Time: 11:30
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\News;
use Phalcon\Paginator\Adapter\QueryBuilder;

class NewsController extends Controller
{
    public function editAction()
    {
        if ($this->request->isPut()) {
            $news = News::findFirst($this->request->getPut('Id'));
            if (!$news) {
                throw new LogicException('请求错误', Status::BadRequest);
            }
            $news->Title = $this->request->getPut('Title');
            $news->Html = $this->request->getPut('Html');
        } elseif ($this->request->isPost()) {
            $news = new News();
            $news->Created = time();
            $news->Title = $this->request->getPost('Title');
            $news->Html = $this->request->getPost('Html');
        } else {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $news->Updated = time();
        if (!$news->save()) {
            $exception = new ParamException(Status::BadRequest);
            $exception->loadFromModel($news);
            throw $exception;
        }
        return $this->response->setJsonContent($news);
    }

    public function listAction()
    {
        $data = $this->request->get();
        $query = News::query()->where(sprintf('Status=%d', News::STATUS_ON))->orderBy('Created desc');
        $paginate = new QueryBuilder([
            'builder' => $query->createBuilder(),
            'limit'   => (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10,
            'page'    => (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1,
        ]);
        $this->outputPagedJson($paginate);
    }

    /**
     * 逻辑删除
     */
    public function statusAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);

        }
        $news = News::findFirst($this->request->getPut('Id'));
        if (!$news) {
            throw new LogicException('请求错误', Status::BadRequest);
        }
        $news->Status = News::STATUS_OFF;
        if ($news->save() === false) {
            $exception = new ParamException(Status::BadRequest);
            $exception->loadFromModel($news);
            throw $exception;
        }
    }
}