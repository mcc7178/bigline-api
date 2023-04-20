<?php
/**
 * Description : 支付回调
 * Author      : Kobin
 * CreateTime  : 2021/8/24 下午3:32
 */

namespace app\api\controller\v1;


use app\api\controller\ApiBaseController;
use app\api\service\payment\LocalPayService;
use app\api\service\payment\OnlinePayRecordsService;
use app\api\service\payment\WePayEz;
use app\api\validate\PaymentValidator;
use app\api\service\payment\PayPal;

class Pay extends ApiBaseController
{
    /**
     * @param array $params
     * @return array|string|true|void
     */
    private function validateParams($params)
    {
        $this->validate($params, PaymentValidator::class . '.' . PaymentValidator::PAY);
    }

    /**
     * AliPay App
     * @return mixed
     */
    public function payOrderAliApp()
    {
        $params = $this->request->post();
        return $this->apiResponse(function () use ($params) {
            $this->validateParams($params);
            $OnlinePay = new OnlinePayRecordsService(OnlinePayRecordsService::PAY_APP_ALI, $params['order_type']);
            return (new WePayEz(OnlinePayRecordsService::PAY_APP_ALI))->payAliApp($OnlinePay->getInfo($params));
        });
    }

    /**
     * AliPay H5
     * @return mixed
     */
    public function payOrderAliH5()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validateParams($params);
            $OnlinePay = new OnlinePayRecordsService(OnlinePayRecordsService::PAY_APP_ALI, $params['order_type']);
            return (new WePayEz(OnlinePayRecordsService::PAY_APP_ALI))->payAliH5($OnlinePay->getInfo($params));
        });
    }

    /**
     * AliPay Native 扫码
     * @return mixed
     */
    public function payOrderAliNative()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validateParams($params);
            $OnlinePay = new OnlinePayRecordsService(OnlinePayRecordsService::PAY_APP_ALI, $params['order_type']);
            return (new WePayEz(OnlinePayRecordsService::PAY_APP_ALI))->payAliScan($OnlinePay->getInfo($params));
        });
    }

    /**
     * WeChatH5
     * @return mixed
     */
    public function payOrderWeChatH5()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validateParams($params);
            $OnlinePay = new OnlinePayRecordsService(OnlinePayRecordsService::PAY_APP_WECHAT, $params['order_type']);
            return (new WePayEz(OnlinePayRecordsService::PAY_APP_WECHAT))->payWeChatH5($OnlinePay->getInfo($params));
        });
    }

    /**
     * WeChatH5 扫码
     * @return mixed
     */
    public function payOrderWeChatNative()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validateParams($params);
            $OnlinePay = new OnlinePayRecordsService(OnlinePayRecordsService::PAY_APP_WECHAT, $params['order_type']);
            return (new WePayEz(OnlinePayRecordsService::PAY_APP_WECHAT))->payWeChatScan($OnlinePay->getInfo($params));
        });
    }

    /**
     * WeChatApp
     * @return mixed
     */
//    public function payOrderwechatApp()
//    {
//        $params = $this->request->post();
//        // validate
//        return $this->response->item(
//            $this->apiResponse(function () use ($params) {
//                $OnlinePay = new OnlinePayRecordsService(OnlinePayRecordsService::PAY_APP_WECHAT, $params['order_type']);
//                return (new WePayEz(OnlinePayRecordsService::PAY_APP_ALI))->payWeChatApp($OnlinePay->getInfo($params));
//            })
//        );
//    }

    /**
     * 獲取PayPal授權地址
     * @return mixed
     */
    public function payOrderPaypal()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validateParams($params);
            return (new PayPal(OnlinePayRecordsService::PAY_APP_PAYPAL))->getPaypalUrl($params);
        });
    }

    /**
     * PayMe
     * @return mixed
     */
    public function payOrderPayMe()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validateParams($params);
            $OnlinePay = new OnlinePayRecordsService(OnlinePayRecordsService::PAY_APP_PAYME, $params['order_type']);
            return (new WePayEz(OnlinePayRecordsService::PAY_APP_PAYME))->payAliH5($OnlinePay->getInfo($params));
        });
    }


    /**
     * @return mixed
     */
    public function getBalance()
    {
        $params = $this->request->get();

        return $this->apiResponse(function () use ($params) {
            $this->validate($params, PaymentValidator::class . '.' . PaymentValidator::BALANCE);
            return (new LocalPayService($this->company_id, $this->request->uid, (int)$this->device_type))->getUserBalance($params['currency']);
        });
    }

    /**
     * @return mixed
     */
    public function payBalance()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validate($params, PaymentValidator::class . '.' . PaymentValidator::BALANCE_PAY);
            return (new LocalPayService($this->company_id, $this->request->uid, (int)$this->device_type))->BalancePay($params);
        });
    }

    /**
     * @return mixed
     */
    public function payOffLine()
    {
        $params = $this->request->get();

        return $this->apiResponse(function () use ($params) {
            $this->validate($params, PaymentValidator::class . '.' . PaymentValidator::OFF_LINE);
            return LocalPayService::offline($params['order_type'], $params['order_id'], $this->company_id);
        });
    }

    public function payOffLineSubmit()
    {
        $params = $this->request->post();

        return $this->apiResponse(function () use ($params) {
            $this->validate($params, PaymentValidator::class . '.' . PaymentValidator::OFF_LINE_SUBMIT);
            return (new  LocalPayService($this->company_id, $this->request->uid, (int)$this->device_type))->offLinePaySubmit($params);
        });
    }

}