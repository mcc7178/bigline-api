<?php
/**
 * Description :
 * Author      : Kobin
 * CreateTime  : 2021/8/15 下午1:55
 */

namespace app\api\controller\v1\Independent;


use app\api\controller\ApiBaseController;
use app\api\service\IndependentOrderService;
use app\api\validate\OrderValidator;
use think\App;
use think\facade\Log;

class Order extends ApiBaseController
{
    private IndependentOrderService $service;

    /**
     * 加购物车
     * @return mixed
     */
    public function addProduct()
    {
        $params = $this->request->post();
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, OrderValidator::class . '.' . OrderValidator::ADD_PRODUCT);
            $service = new IndependentOrderService(
                $this->company_id,
                $this->request->uid,
                $this->request->header('Device'),
                $params
            );
            return $service->addProduct();
        });
    }

    /**
     * 直接购买
     * @return mixed
     */
    public function calculate()
    {
        $params = $this->request->post();
        Log::info('自由行下單預覽：' . json_encode($params));
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, OrderValidator::class . '.' . OrderValidator::CALCULATE);
            $service = new IndependentOrderService(
                $this->company_id,
                $this->request->uid,
                $this->request->header('Device'),
                $params
            );
            return $service->getCalculateResult();
        });
    }

    /**
     * 购买购物车中的产品
     * @return mixed
     */
    public function buy()
    {
        $params = $this->request->post();
        Log::info(json_encode($params));
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, OrderValidator::class . '.' . OrderValidator::CREATE);
            $service = new IndependentOrderService(
                $this->company_id,
                $this->request->uid,
                $this->request->header('Device'),
                $params
            );
            return $service->orderCreate();
        });
    }
}