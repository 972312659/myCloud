<?php

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Models\News;

/**
 * 头条
 * Class NewsController
 * @Anonymous
 * @package App\Controllers
 */
class NewsController extends Controller
{
    const HtmlPrefix = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset=“utf-8”>
<meta name="viewport" content="width=device-width,initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
<style>
img{max-width:100%;}
</style>
</head>
<body>
HTML;
    const HtmlSuffix = <<<HTML
</body>
</html>
HTML;

    public function listAction()
    {
        $news = News::find([
            'conditions' => 'Status=?0',
            'bind'       => [News::STATUS_ON],
            'columns'    => 'Id, Title, Created',
            'order'      => 'Id desc',
        ]);
        if (!$news) {
            throw new LogicException('内容不存在', Status::NotFound);
        }
        return $this->response->setJsonContent($news);
    }

    public function detailAction()
    {
        $news = News::findFirst($this->request->get('Id'));
        if (!$news) {
            throw new LogicException('内容不存在', Status::NotFound);
        }
        return $this->response->setContent(self::HtmlPrefix . $news->Html . self::HtmlSuffix);
    }
}