<?php
/**
 * Description :
 * Author      : Kobin
 * CreateTime  : 2021/8/26 上午11:56
 */

namespace app\api\service\payment;

/**
 * TODO
 * Class PayMe
 * @package app\api\service\payment
 */

class PayMe extends BasePayment
{
    public function payApp()
    {
        dd($this->config);
    }

}