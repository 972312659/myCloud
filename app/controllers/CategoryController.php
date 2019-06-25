<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/10
 * Time: 下午3:11
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Models\Category;
use Phalcon\Http\Response;

class CategoryController extends Controller
{
    public function createAction()
    {
        $response = new Response();
        if ($this->request->isPost()) {
            $category = new Category();
            $data = $this->request->getPost();
            if ($category->save($data) === false) {
                $messages = $category->getMessages();
                $response->setStatusCode(Status::BadRequest);
                $response->setJsonContent($messages);
                return $response;
            }
            $response->setStatusCode(Status::Created);
            $response->setJsonContent($category);
            return $response;
        }
        $response->setStatusCode(Status::MethodNotAllowed);
        return $response;
    }

    public function updateAction($id)
    {
        $response = new Response();
        if ($this->request->isPut()) {
            $category = Category::findFirst(sprintf('Id=%d', $id));
            $data = $this->request->getPut();
            if ($category->save($data) === false) {
                $messages = $category->getMessages();
                $response->setStatusCode(Status::BadRequest);
                $response->setJsonContent($messages);
                return $response;
            }
            $response->setStatusCode(Status::Created);
            $response->setJsonContent($category);
            return $response;
        }
        $response->setStatusCode(Status::MethodNotAllowed);
        return $response;
    }

    public function readAction($id)
    {
        $response = new Response();
        $category = Category::findFirst(sprintf('Id=%d', $id));
        if (!$category) {
            $response->setStatusCode(Status::NotFound);
            return $response;
        }
        $response->setJsonContent($category);
        return $response;
    }

    public function listAction()
    {
        $response = new Response();
        $categorys = Category::find();
        $response->setJsonContent($categorys);
        return $response;
    }

    public function deleteAction($id)
    {
        $response = new Response();
        if ($this->request->isDelete()) {
            $category = Category::findFirst(sprintf('Id=%d', $id));
            if (!$category) {
                $response->setStatusCode(Status::NotFound);
                return $response;
            }
            $category->delete();
            $response->setJsonContent(['message'=>'success']);
            return $response;
        }
        $response->setStatusCode(Status::MethodNotAllowed);
        return $response;
    }
}