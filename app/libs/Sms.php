<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/17
 * Time: 15:50
 */

namespace App\Libs;

use App\Enums\RedisName;
use App\Enums\Status;
use App\Exceptions\LogicException;
use Phalcon\Di\FactoryDefault;
use Pheanstalk\Pheanstalk;

class Sms
{
    /**
     * 每个手机号每日接收短信验证码短信最大限制
     */
    const MAX_PER_DAY = 10;

    /**
     * 手机号接收短信验证码频率
     */
    const SEQUENCE = 60;

    /**
     * @var Pheanstalk
     */
    protected $queue;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var $redis
     */
    protected $redis;

    /**
     * Sms constructor.
     * @param Pheanstalk $queue
     * @param Session $session
     * @param \Redis $redis
     */

    public function __construct(Pheanstalk $queue, Session $session = null, \Redis $redis = null)
    {
        $this->queue = $queue;
        $this->session = $session;
        $this->redis = $redis;
    }

    /**
     * @param string $mobile  手机号
     * @param string $content 短信内容
     * @param string $channel 渠道
     * @param string $captcha 验证码
     * @throws LogicException
     */
    public function send(string $mobile, string $content, string $channel = '', string $captcha = '')
    {
        if ($channel || $captcha) {
            if ($channel && $captcha) {
                $totalKey = sprintf(RedisName::SMS_TOTAL, date('Ymd'), $mobile);
                $lastKey = sprintf(RedisName::SMS_LAST, $mobile);
                // 查询当日已发短信总数
                $total = (int)$this->redis->get($totalKey);
                if ($total > self::MAX_PER_DAY) {
                    throw new LogicException('手机号【' . $mobile . '】超出当时接收短信最大条数', Status::BadRequest);
                }
                // 用redis set not exists方法看返回结果是否成功
                if (!$this->redis->setnx($lastKey, 1)) {
                    // 未设置成功则返回剩余秒数
                    $remaining = $this->redis->ttl($lastKey);
                    // 防止没有ttl的字段
                    if ($remaining === -1) {
                        $this->redis->expire($lastKey, self::SEQUENCE);
                        $remaining = self::SEQUENCE;
                    }
                    throw new LogicException('发送短信过于频繁，请' . $remaining . '秒后重试', Status::BadRequest);
                } else {
                    $this->redis->expire($lastKey, self::SEQUENCE);
                }

                // 如果没有设置总数计数，一天后过期
                if ($total === 0) {
                    $this->redis->setex($totalKey, 86400, 1);
                } else {
                    $this->redis->incr($totalKey);
                }
                $captchaKey = sprintf(RedisName::SMS_CAPTCHA, (int)(microtime(true) * 1000000));
                // 设置短信验证码，5分钟过期
                $this->redis->setex($captchaKey, 300, $captcha);
                // 将key写到session中
                $this->session->set($channel, $captchaKey);
            } else {
                throw new \InvalidArgumentException('渠道和验证码必须同时设置');
            }
        }
        $this->queue->putInTube('sms', json_encode(['mobile' => $mobile, 'content' => $content], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 验证短信验证码是否正确
     * @param string $channel 渠道
     * @param string $needle  用户输入的验证码
     * @return bool
     */
    public function verify(string $channel, string $needle): bool
    {
        $captchaKey = $this->session->get($channel);
        return $this->redis->get($captchaKey) === $needle;
    }

    /**
     * 短信模板
     * @param string $mobile  手机号
     * @param string $content 内容
     */
    public function sendMessage(string $mobile, string $content, string $extend = null)
    {
        $this->queue->putInTube('sms', json_encode(['mobile' => $mobile, 'content' => $content, 'extendedCode' => $extend], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 短信模板 使用java发送短信
     * @param string $mobile  手机号
     * @param string $content 内容
     */
    public static function useJavaSendMessage(string $mobile, String $templateNo, Array $templateParam, string $extend = null, array $args = [])
    {
        $data = [
            //手机号
            'phoneNumbers'    => $mobile,
            //短信模板编号
            'templateNo'      => $templateNo,
            //发送场景，可不传
            'scene'           => '',
            //模板变量
            'templateParam'   => $templateParam,
            //扩展码
            'smsUpExtendCode' => $extend,
        ];
        //业务扩展字段
        if ($args) {
            $data['args'] = $args;
        }
        //TODO 修改url地址
        $re = Curl::gain('POST', FactoryDefault::getDefault()->get('config')->get('sms')->get('url'), "application/json", json_encode($data));
    }
}