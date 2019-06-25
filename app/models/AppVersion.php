<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class AppVersion extends Model
{
    //平台 1=>ios 2=>android
    const PLATFORM_IOS = 1;
    const PLATFORM_ANDROID = 2;
    const PLATFORM_NAME = [1 => 'ios', 2 => 'android'];

    //是否强制更新 0=>否 1=>是
    const FORCED_NO = 0;
    const FORCED_YES = 1;

    //更新链接
    const URL = "app version's url";

    public $Version;

    public $Platform;

    public $Forced;

    public $Created;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'AppVersion';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('Version',
            new PresenceOf(['message' => '版本号不能为空'])
        );
        $validator->rules('Platform', [
            new PresenceOf(['message' => '平台类型不能为空']),
            new Digit(['message' => '平台类型格式错误']),
        ]);
        $validator->rules('Forced', [
            new PresenceOf(['message' => '是否强制更新不能为空']),
            new Digit(['message' => '是否强制更新格式错误']),
        ]);
        return $this->validate($validator);
    }
}
