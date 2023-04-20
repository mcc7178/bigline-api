<?php
/**
 * Description : WePayEz include aliPay Hk & weChatPay Hk
 * Author      : Kobin
 * CreateTime  : 2021/8/26 上午11:56
 */

namespace app\api\service\payment;


use app\api\lib\BizException;
use comm\service\EncryptionService;
use comm\service\HttpService;
use SimpleXMLElement;
use think\facade\Log;
use think\facade\Route;

class WePayEz extends BasePayment
{
    const ALIPAY_WAP = 'pay.alipay.wappay.intl'; // ALI WAP
    const ALIPAY_SCAN = 'pay.alipay.native.intl'; // ALI SCAN
    const ALIPAY_APP = 'pay.alipay.app.intl'; // ALI APP

    const WECHAT_WAP = 'pay.weixin.wap.intl'; // WECHAT WAP
    const WECHAT_SCAN = 'pay.weixin.native.intl'; // WECHAT Native

    private $ali_key = '';
    private $wechat_key = '';


    private $base_url = 'https://gateway.wepayez.com/pay/gateway';

    public function __construct($payAPp)
    {
        parent::__construct($payAPp);
        $this->ali_key = env('wepayez.ali_key');
        $this->wechat_key = env('wepayez.wechat_key');
    }

    /**
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function payAliApp($params)
    {
        $data = array_merge(
            $this->config,
            [
                'body' => $params['payDsc'],
//                'callback_url' => (string)Route::buildUrl('/api/v1/notify/alipay_return') . '?trade_no=' . (new EncryptionService())->encrypt($params['paySn']),
                'mch_create_ip' => $_SERVER['SERVER_ADDR'],
                'notify_url' => env('site.site_url') . '/api/v1/notify/alipay_notify',
                'out_trade_no' => $params['paySn'],
                'service' => self::ALIPAY_APP,
                'total_fee' => $params['payAmount'],
                'nonce_str' => 'bigline',
                'sign_type' => 'SHA256',
            ]
        );
        $xml = $this->handleData($data, $this->ali_key);
        $ret = $this->http($xml);
        return [
            'orderStr' => $ret['pay_info']
        ];
    }

    /**
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function payALiH5($params)
    {
        try {
            $data = array_merge(
                $this->config,
                [
                    'body' => $params['payDsc'],
//                    'callback_url' => (string)Route::buildUrl('/api/v1/notify/alipay_return') . '?trade_no=' . (new EncryptionService())->encrypt($params['paySn']),
                    'mch_create_ip' => $_SERVER['SERVER_ADDR'],
                    'notify_url' => env('site.site_url') . '/api/v1/notify/alipay_notify',
                    'out_trade_no' => $params['paySn'],
                    'service' => self::ALIPAY_WAP,
                    'total_fee' => $params['payAmount'],
                    'nonce_str' => 'bigline',
                    'sign_type' => 'SHA256',
                ]
            );
            $xml = $this->handleData($data, $this->ali_key);
            $ret = $this->http($xml);
            return ['pay_info' => $ret['pay_url']];
        } catch (\Exception $exception) {
            BizException::throwException(50001, $exception->getMessage());
        }
    }


    /**
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function payAliScan($params)
    {
        $data = array_merge(
            $this->config,
            [
                'body' => $params['payDsc'],
                'mch_create_ip' => $_SERVER['SERVER_ADDR'],
                'notify_url' => env('site.site_url') . '/api/v1/notify/alipay_notify',
                'out_trade_no' => $params['paySn'],
                'service' => self::ALIPAY_SCAN,
                'total_fee' => $params['payAmount'],
                'nonce_str' => 'bigline',
                'sign_type' => 'MD5',
            ]
        );
        $xml = $this->handleData($data, $this->ali_key, 'MD5');
        $ret = $this->http($xml);
        return [
            'code_url' => $ret['code_url'],
            'code_img_url' => $ret['code_img_url']
        ];
    }

    /**
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function payWeChatScan($params)
    {
        $data = array_merge(
            $this->config,
            [
                'body' => $params['payDsc'],
                'mch_create_ip' => $_SERVER['SERVER_ADDR'],
                'notify_url' => env('site.site_url') . '/api/v1/notify/wechat_notify',
                'out_trade_no' => $params['paySn'],
                'service' => self::WECHAT_SCAN,
                'total_fee' => $params['payAmount'],
                'nonce_str' => 'bigline',
                'sign_type' => 'MD5',
            ]
        );
        $xml = $this->handleData($data, $this->wechat_key, 'MD5');
        $ret = $this->http($xml);
        return [
            'code_url' => $ret['code_url'],
            'code_img_url' => $ret['code_img_url']
        ];
    }

    /**
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function payWeChatH5($params)
    {
        $data = array_merge(
            $this->config,
            [
                'body' => $params['payDsc'],
                'mch_create_ip' => $_SERVER['SERVER_ADDR'],
                'notify_url' => env('site.site_url') . '/api/v1/notify/wechat_notify',
                'out_trade_no' => $params['paySn'],
                'service' => self::WECHAT_WAP,
                'total_fee' => $params['payAmount'],
                'nonce_str' => 'bigline',
                'sign_type' => 'MD5',
            ]
        );
        $xml = $this->handleData($data, $this->wechat_key, 'MD5');
        $ret = $this->http($xml);
        dd($ret);
        return ['pay_url' => $ret['pay_url']];
    }


//    private function setAliData($params, $other)
//    {
//        $data = array_merge(
//            $this->config,
//            [
//                'body' => $params['payDsc'],
//                'callback_url' => (string)Route::buildUrl('/api/v1/notify/alipay_return') . '?trade_no=' . (new EncryptionService())->encrypt($params['paySn']),
//                'mch_create_ip' => $_SERVER['REMOTE_ADDR'],
//                'notify_url' => (string)Route::buildUrl('/api/v1/notify/alipay_notify'),
//                'out_trade_no' => $params['paySn'],
//                'service' => self::ALIPAY_SCAN,
//                'total_fee' => $params['payAmount'],
//                'nonce_str' => 'bigline',
//                'sign_type' => 'SHA256',
//            ]
//        );
//        if (!empty($other)) {
//            $data = array_merge($data, $other);
//        }
//        return $data;
//    }

    /**
     * @param $data
     * @param $apiKey
     * @param string $signType
     * @return mixed
     */
    private function handleData($data, $apiKey, $signType = 'SHA256')
    {
        ksort($data);
        $url_arr = [];
        foreach ($data as $key => $value) {
            $url_arr[] = $key . '=' . $value;
        }
        if ($signType == 'MD5') {
            $data['sign'] = strtoupper(md5(implode('&', $url_arr) . '&key=' . $apiKey));
        } else if ($signType == 'SHA256') {
            $data['sign'] = strtoupper(hash('sha256', implode('&', $url_arr) . '&key=' . $apiKey));
        }

        ksort($data);
        $xml = new SimpleXMLElement('<xml/>');
        foreach ($data as $key => $value) {
            $xml->addChild($key, $value);
        }
        return $xml->asXML();
    }


    /**\
     * @param $xml_data
     * @return mixed
     * @throws \Exception
     */
    private function http($xml_data)
    {
        $ch = curl_init($this->base_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSLVERSION, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSLVERSION, 1);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $ret = json_decode(json_encode(simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
            Log::info(json_encode($ret));
            if ($ret['status'] != 0) {
                BizException::throwException(9000, '支付請求出錯:message:' . $ret['message']);
            } else {
                if ($ret['result_code'] != 0) {
                    BizException::throwException(9000, '支付請求出錯:' . $ret['err_msg']);
                }
            }
            return $ret;
        } else {
            Log::error(curl_error($ch));
            BizException::throwException(9000, '支付請求出錯');
        }


    }
}