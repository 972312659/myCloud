<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/31
 * Time: 下午7:32
 */

namespace App\Controllers;

use App\Enums\RedisName;
use App\Enums\Status;
use App\Exceptions\ParamException;
use Phalcon\Http\Response;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Endroid\QrCode\QrCode;

class TermController extends Controller
{
    const QRCODEKEYS = 'QRCODE:%s%s';

    private $statusWait = "wait";
    private $statusKey = "scanStatus";

    /**
     * APP扫描获得设备信息
     * @Authorize
     */
    public function getTermInfoAction()
    {
        $exp = new ParamException(Status::BadRequest);
        $data = $this->request->getPost();
        $validator = new Validation();
        $validator->rules('keys', [
            new PresenceOf(['message' => 'keys不能为空']),
        ]);
        $ret = $validator->validate($data);
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }
        $term_id = $this->redis->hGet($data['keys'], $data['keys']);
        if (empty($term_id)) {
            $exp->add('keys', '二维码已失效!');
            throw $exp;
        }
        $status = $this->redis->hGet($data['keys'], 'status');
        if (!$status) {
            $exp->add('status', '二维码已使用');
            throw $exp;
        }
        //todo 验证该设备是否属于自己的设备
        //获取该设备信息
        $result['Id'] = $term_id;
        $this->response->setJsonContent(
            $result
        );
    }


    /**
     * APP验证并登录
     * @Authorize
     */
    public function loginAction()
    {
        $exp = new ParamException(Status::BadRequest);
        $data = $this->request->getPost();
        $validator = new Validation();
        $validator->rules('keys', [
            new PresenceOf(['message' => 'keys不能为空']),
        ]);
        $ret = $validator->validate($data);
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }
        $key = $data['keys'];
        $result = $this->redis->get($key);
        if (!$result) {
            $exp->add('keys', '二维码已失效!');
            throw $exp;
        }
        $status = json_decode($result, true);;
        if (!isset($status[$this->statusKey])) {
            $exp->add('status', '二维码已使用');
            throw $exp;
        }

        //获取当前auth信息
        $auth = $this->session->get('auth');

        //给token对应的登录信息
        $this->redis->getSet($key, json_encode($auth));

        // 绑定pad最新token
        $this->redis->getSet(RedisName::TokenPad . $auth['OrganizationId'], $key);

        $this->response->setStatusCode(Status::OK);
    }


    /**
     * 检测回调
     * @Anonymous
     */
    public function checkAction()
    {
        $validation = new Validation();
        $validation->rules('Id', [
            new PresenceOf(['message' => '设备Id不能为空']),
        ]);
        $validation->rules('Rand', [
            new PresenceOf(['message' => '异常']),
        ]);
        $ex = new ParamException(Status::BadRequest);
        $data = $this->request->getPost();
        $ret = $validation->validate($data);
        if (count($ret) > 0) {
            $ex->loadFromMessage($ret);
            throw $ex;
        }
        $key = md5(sprintf(self::QRCODEKEYS, $data['Id'], $data['Rand']));

        $result = $this->redis->get($key);
        if (!$result) {
            $ex->add('Id', '二维码已失效!');
            throw $ex;
        }
        $auth = json_decode($result, true);
        if (isset($auth[$this->statusKey]) && $auth[$this->statusKey] == $this->statusWait) {
            return $this->response->setJsonContent(["message" => "等待扫描"]);
        }

        return $this->response->setJsonContent($auth);
    }

    /**
     * 设备获取keys
     * @Anonymous
     */
    public function getkeysAction()
    {
        // $validation = new Validation();
        // $validation->rules('Id', [
        //     new PresenceOf(['message' => 'Id不能为空']),
        // ]);
        // $ex = new ParamException(Status::BadRequest);
        // $data = $this->request->getPost();
        // $ret = $validation->validate($data);
        // if (count($ret) > 0) {
        //     $ex->loadFromMessage($ret);
        //     throw $ex;
        // }
        // $rand = microtime(true);
        // $fieldkeys = md5(sprintf(self::QRCODEKEYS, $data['Id'], $rand));
        //
        // $setvalue = [$fieldkeys => $data['Id'], 'status' => $filedstatus];
        // $this->redis->hMSet($fieldkeys, $setvalue);
        // $this->redis->expire($fieldkeys, 300);
        //
        // $this->response->setJsonContent([
        //     'keys' => $fieldkeys,
        // ]);
    }

    /**
     * 设备获取二维码
     * @Anonymous
     */
    public function getQrCodeAction()
    {
        $validation = new Validation();
        $validation->rules('Id', [
            new PresenceOf(['message' => '设备Id不能为空']),
        ]);
        $ex = new ParamException(Status::BadRequest);
        $data = $this->request->getPost();
        $ret = $validation->validate($data);
        if (count($ret) > 0) {
            $ex->loadFromMessage($ret);
            throw $ex;
        }
        $rand = time();
        $fieldkeys = md5(sprintf(self::QRCODEKEYS, $data['Id'], $rand));
        $setvalue = [$this->statusKey => $this->statusWait];
        $this->redis->set($fieldkeys, json_encode($setvalue));
        $this->redis->expire($fieldkeys, 300);

        //赋协议
        $protocol = "peach://term/login?id=";
        $link = $protocol . $fieldkeys;

        $qrCode = new QrCode($link);
        $qrCode->setSize(300);
        $qrCode->setWriterByName('png');
        $qrCodeString = 'data:image/png;base64,' . base64_encode($qrCode->writeString());

        $this->response->setJsonContent([
            'QrCode' => $qrCodeString,
            'Rand'   => $rand,
            'key'    => $link,
        ]);
    }

    public function logoutAction()
    {
        $response = new Response();
        $this->session->remove('auth');
        $response->setStatusCode(Status::OK);
        $response->setJsonContent([
            'message' => 'logout ok',
        ]);
        return $response;
    }

}
