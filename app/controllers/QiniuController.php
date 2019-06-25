<?php
/**
 * Created by PhpStorm.
 * User: 30327
 * Date: 2017/6/20
 * Time: 20:31
 */

namespace App\Controllers;

use Phalcon\Http\Response;

class QiniuController extends Controller
{
    public function getQiniuTokenAction()
    {
        $response = new Response();
        // 空间名  https://developer.qiniu.io/kodo/manual/concepts https://avatars.store.100cbc.com
        $bucket = 'referral';
        // 生成上传Token
        $token = $this->qiniu->uploadToken($bucket, null, 3600);
        $response->setJsonContent(['uptoken' => $token]);
        return $response;
    }

    /**
     * @Anonymous
     */
    public function uploadAction()
    {
        require APP_PATH . '/libs/baidu/controller.php';
    }
}