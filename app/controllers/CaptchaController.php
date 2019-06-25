<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/24
 * Time: 下午6:05
 */

namespace App\Controllers;

use \Gregwar\Captcha\CaptchaBuilder;

class CaptchaController extends Controller
{
    /**
     * @var CaptchaBuilder
     */
    private $builder;

    public function initialize()
    {
        $this->builder = new CaptchaBuilder();
        $this->builder->build();
    }

    /**
     * @Anonymous
     */
    public function showAction()
    {
        header('Content-type: image/jpeg');
        $this->builder->output();
        $this->session->set('captcha', $this->builder->getPhrase());
    }
}