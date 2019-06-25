<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/10
 * Time: 下午4:34
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Libs\MessageSend;
use Phalcon\Http\Response;

class SendMessageController extends Controller
{
    /**
     * 群推送
     */
    public function sendAction()
    {
        $response = new Response();
        $result = MessageSend::pushMessageToApp();
        if ($result['result'] !== 'ok') {
            $response->setStatusCode(Status::BadRequest);
            return $response;
        }
        $response->setJsonContent($result);
        return $response;
    }
}