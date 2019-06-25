<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/20
 * Time: 10:56
 */

namespace App\Libs;


use App\Models\User;
use Pheanstalk\Pheanstalk;

class Push
{
    const TITLE_TRANSFER = '转诊通知';
    const TITLE_REGISTRATION = '挂号通知';
    const TITLE_FUND = '账户通知';
    const TITLE_STOCK = '库存通知';
    const TITLE_COMBO = '套餐通知';
    protected $queue;

    public function __construct(Pheanstalk $queue)
    {
        $this->queue = $queue;
    }

    public function send($user, string $title, string $content)
    {
        if (!$user->AppId || $user->Switch === 2) {
            return;
        }
        $this->queue->putInTube('push', json_encode([
            'cid'     => $user->AppId,
            'factory' => $user->Factory,
            'data'    => [
                'title'   => $title,
                'content' => $content,
                'payload' => [
                    'type' => 'notify',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }
}