<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class AppResource extends Model
{
    //平台 1=>ios 2=>android
    const PLATFORM_IOS = 1;
    const PLATFORM_ANDROID = 2;
    const PLATFORM_NAME = [1 => 'ios', 2 => 'android'];

    //不同资源的git路径
    const PATH_GIT_IOS = '/var/www/cloud';
    const PATH_GIT_ANDROID = '/var/www/cloud';

    //zip包存放路径
    const PATH_ZIP = '/var/david/';

    //更新链接
    const ANDROID_URL = 'http://www.100cbc.com/app-org-release.apk';
    const IOS_URL = 'https://itunes.apple.com/cn/app/%E4%BA%91%E8%BD%AC%E8%AF%8A/id1264459655?mt=8';

    public $HashKey;

    public $Platform;

    public $Created;

    public $ResourceUrl;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'AppResource';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('HashKey',
            new PresenceOf(['message' => 'hash key不能为空'])
        );
        $validator->rules('Platform', [
            new PresenceOf(['message' => '平台类型不能为空']),
            new Digit(['message' => '平台类型格式错误']),
        ]);
        return $this->validate($validator);
    }
}
