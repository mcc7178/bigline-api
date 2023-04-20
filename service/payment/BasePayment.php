<?php
/**
 * Description :
 * Author      : Kobin
 * CreateTime  : 2021/8/26 上午11:56
 */

namespace app\api\service\payment;


use comm\model\finance\ReceivePaymentOrdersModel;
use think\facade\Route;

class BasePayment
{
    protected $config = [];

    public function __construct($payAPp)
    {
        $this->config = config("payment." . $payAPp);
    }

    /**
     * 支付方式
     * @param $client
     * @return array
     */
    public static function types($client)
    {
        // 通用
        $types = [
            [
                'type' => 1,
                'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/offline.png',
                'url' => (string)Route::buildUrl('/api/v1/pay/off_line'),
                'name' => '銀行轉帳／便利店繳付/PPS',
            ],
            [
                'type' => 0,
                'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/balance.png',
                'url' => (string)Route::buildUrl('/api/v1/pay/balance'),
                'name' => '賬戶餘額支付',
            ],
        ];
        // APP
        if ($client == 2 || $client == 3) {
            $types = array_merge($types,
                [
                    [
                        'type' => 2,
                        'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/alipay.png',
                        'url' => (string)Route::buildUrl('/api/v1/pay/alipay_app'),
                        'name' => '支付寶',
                    ],
//                    [
//                        'type' => 3,
//                        'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/paypal.png',
//                        'url' => (string)Route::buildUrl('/api/v1/pay/paypal'),
//                        'name' => 'PayPal',
//                    ],
//                    [
//                        'type' => 4,
//                        'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/payme.png',
//                        'url' => (string)Route::buildUrl('/api/v1/pay/payme'),
//                        'name' => 'PayMe by HSBC',
//                    ],
                ]
            );
        } else if ($client == 4) {
            // WEB
            $types = array_merge($types,
                [
                    [
                        'type' => 5,
                        'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/alipay.png',
                        'url' => (string)Route::buildUrl('/api/v1/pay/alipay_native'),
                        'name' => '支付寶',
                    ],
                    [
                        'type' => 6,
                        'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/wechat.png',
                        'url' => (string)Route::buildUrl('/api/v1/pay/wechat_native'),
                        'name' => '微信支付',
                    ]
                ]
            );
        } else if ($client == 5) {
            // WAP
            $types = array_merge($types,
                [
                    [
                        'type' => 7,
                        'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/alipay.png',
                        'url' => (string)Route::buildUrl('/api/v1/pay/alipay_h5'),
                        'name' => '支付寶',
                    ],
//                    [
//                        'type' => 8,
//                        'img' => 'https://bigline.oss-cn-shenzhen.aliyuncs.com/test/2021/static/wechat.png',
//                        'url' => (string)Route::buildUrl('/api/v1/pay/wechat_h5'),
//                        'name' => '微信支付H5',
//                    ]
                ]
            );
        }

        return $types;
    }

    /**
     * @return string[]
     */
    public static function offLinePayTypes()
    {
        $t = ReceivePaymentOrdersModel::STORE_TYPE_STR;
        $ret = [];
        if (!empty($t)) {
            foreach ($t as $k => $item) {
                $n['type_method'] = $k;
                $n['name'] = $item;
                array_push($ret, $n);
            }
        }
        return $ret;
    }

}