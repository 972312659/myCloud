<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:44
 */

namespace App\Models;


use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class UserEvent extends Model
{
    public $UserId;

    public $EventId;

    public $AcceptWay;

    public $OrganizationId;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'UserEvent';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule(['UserId', 'EventId'],
            new Digit([
                'message' => [
                    'EventId' => 'EventId必须为整形数字',
                    'UserId'  => 'UserId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }

    /**
     * 获取该机构该事件下的所有人员
     * @param int $organizationId
     * @param int $eventId
     * @return array
     */
    public static function getUsers(int $organizationId, int $eventId)
    {
        $organizationUsers = OrganizationUser::find(sprintf('OrganizationId=%d', $organizationId));
        $events = UserEvent::query()
            ->inWhere('UserId', array_column($organizationUsers->toArray(), 'UserId'))
            ->andWhere(sprintf('EventId=%d', $eventId))
            ->execute();
        $filtered = $organizationUsers->filter(function ($organizationUser) use ($events) {
            foreach ($events as $event) {
                if ($organizationUser->UserId === $event->UserId) {
                    $organizationUser->AcceptWay = $event->AcceptWay;
                    $organizationUser->AppId = $organizationUser->User->AppId;
                    $organizationUser->Phone = $organizationUser->User->Phone;
                    return $organizationUser;
                }
            }
        });
        return $filtered;
    }

    /**
     * app消息推送的人
     */
    public static function user(int $organizationId)
    {
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst(sprintf('OrganizationId=%d', $organizationId));
        if ($organizationUser) {
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $organizationUser->UserId));
            $organizationUser->AppId = $user ? ($user->AppId ?: '') : '';
            $organizationUser->Phone = $user ? ($user->Phone) : '';
            $organizationUser->Factory = $user ? ($user->Factory ?: '') : '';
        }
        return $organizationUser ?: null;
    }
}