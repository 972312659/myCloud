<?php

namespace App\Controllers;

use App\Enums\Status;
use Phalcon\Http\Response;
//use Tencent\TLSSigAPI;

/**
 * Class IndexController
 * @Anonymous
 * @package App\Controllers
 */
class IndexController extends Controller
{
//    static private $private_key_string = <<<'EOT'
//-----BEGIN PRIVATE KEY-----
//MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgoRhR6Fkcm8u1wEfx
//02jaCsRgUo5D5vewK4ir0Ka3GMqhRANCAATxJ7paUcSlqweWyDLDh/orWdvj5Kat
//CgzwFJDHjJmgo+rel10fyZeGU/d8Vh8sjxMpieo4nc4ShjAg+gG1K9bQ
//-----END PRIVATE KEY-----
//EOT;
//    static private $public_key_string = <<<'EOT'
//-----BEGIN PUBLIC KEY-----
//MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE8Se6WlHEpasHlsgyw4f6K1nb4+Sm
//rQoM8BSQx4yZoKPq3pddH8mXhlP3fFYfLI8TKYnqOJ3OEoYwIPoBtSvW0A==
//-----END PUBLIC KEY-----
//EOT;


    public function indexAction()
    {

    }

    public function notFoundAction()
    {
        $response = new Response();
        $response->setStatusCode(Status::NotFound);
        $response->setJsonContent([
            'message' => 'Resource doesn\'t exists.'
        ]);
        return $response;
    }

    public function appleAction()
    {
        $this->response->setJsonContent([
            'applinks' => [
                'apps'    => [],
                'details' => [
                    [
                        'appID' => 'WUQ7396F55.com.100cbc.referral',
                        'paths' => ['*'],
                    ]
                ],
            ]
        ]);
    }
}