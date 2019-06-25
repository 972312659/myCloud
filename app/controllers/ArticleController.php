<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/17
 * Time: 上午11:26
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Article;
use App\Models\Category;
use App\Models\Organization;
use Phalcon\Paginator\Adapter\QueryBuilder;

class ArticleController extends Controller
{
    /**
     * 创建公告 修改公告
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        $now = time();
        try {
            if ($this->request->isPost()) {
                $article = new Article();
                $data = $this->request->getPost();
                $data['CreateTime'] = $now;
                $data['OrganizationId'] = $this->session->get('auth')['OrganizationId'];
                $data['Status'] = Article::STATUS_UN;
                $data['AcceptOrganization'] = Article::ACCEPT_BOTH;
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $article = Article::findFirst(sprintf('Id=%d', $data['Id']));
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            //即时发布
            if ($data['Style'] == Article::STYLE_NOW || empty($data['ReleaseTime']) || !isset($data['ReleaseTime']) || $data['ReleaseTime'] <= $data['CreateTime']) {
                $data['ReleaseTime'] = $now;
                $data['Status'] = Article::STATUS_ED;
            }
            if ($article->save($data) === false) {
                $exception->loadFromModel($article);
                throw $exception;
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($article);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
        return;
    }

    /**
     * 读取一条公告
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $article = Article::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$article) {
                throw $exception;
            }
            $this->response->setJsonContent($article);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 公告列表
     */
    public function listAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            //将定时发布的状态变为已发布
            $notice = Article::find([
                'OrganizationId=?0 and ReleaseTime<=?1 and Status=?2',
                'bind' => [$auth['HospitalId'], time(), Article::STATUS_UN],
            ]);
            if ($notice) {
                foreach ($notice as $v) {
                    $v->Status = Article::STATUS_ED;
                    $v->save();
                }
            }
            $data = $this->request->getPost();
            $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
            $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
            $query = $this->modelsManager->createBuilder();
            $query->columns('A.Id,A.Title,A.Author,A.Content,A.CategoryId,A.OrganizationId,A.ReleaseTime,A.CreateTime,A.Status,A.Style,O.Name as OrganizationName');
            $query->addFrom(Article::class, 'A');
            $query->join(Organization::class, 'O.Id=A.OrganizationId', 'O');
            $query->where('A.OrganizationId=' . $auth['HospitalId']);
            $query->andWhere('A.CategoryId=' . Category::NOTICE);
            if (!empty($data['Title']) && isset($data['Title'])) {
                $query->andWhere("A.Title=:Title:", ['Title' => $data['Title']]);
            }
            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                $query->andWhere("A.ReleaseTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
            }
            //结束时间
            if (!empty($data['EndTime']) && isset($data['EndTime'])) {
                if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                    throw $exception;
                }
                $query->andWhere("A.ReleaseTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
            }
            if (!empty($data['Status']) && isset($data['Status'])) {
                $query->andWhere("A.Status=:Status:", ['Status' => $data['Status']]);
            }
            $query->orderBy('A.CreateTime desc');
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
                $data['OrganizationName'] = $data['OrganizationId'] === 0 ? '平台' : $data['OrganizationName'];
                $data['Content'] = strip_tags($data['Content']);
            }
            $result = [];
            $result['Data'] = $datas;
            $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 删除一条公告
     */
    public function deleteAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isDelete()) {
                $article = Article::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
                if (!$article || (int)$article->OrganizationId !== (int)$this->user->OrganizationId) {
                    throw $exception;
                }
                $article->delete();
                return;
            }
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}