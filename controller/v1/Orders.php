<?php

namespace app\api\controller\v1;

use app\api\controller\ApiBaseController;
use app\api\lib\BizException;
use app\api\service\OrderService;
use app\api\validate\OrderValidator;
use comm\constant\CN;
use think\App;
use think\exception\ValidateException;

/**
 * 我的订单
 * Class Orders
 * @package app\api\controller\v1
 */
class Orders extends ApiBaseController
{
    private $service;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->service = new OrderService($this->company_id, $this->request->uid, (int)$app->request->header('Device', 3));
    }

    /**
     * 页面统计数据
     * @return mixed
     */
    public function stat()
    {
        $params = $this->request->get();
        return $this->apiResponse(function () use ($params) {
            return $this->service->stat($params);
        });
    }

    /**
     * 订单列表
     * @return mixed
     */
    public function list()
    {
        $params = $this->request->get();
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, OrderValidator::class . '.' . OrderValidator::LIST);
            return $this->service->list($params);
        });
    }

    /**
     * 订单详情
     */
    public function read()
    {
        $params = $this->request->get();
        $params['member_id'] = $this->request->user()->id;
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, OrderValidator::class . '.' . OrderValidator::READ);
            return $this->service->read($params);
        });
    }

    /**
     * 订单出行人
     * @return mixed
     */
    public function order_customers()
    {
        $params = $this->request->get();
        $params['member_id'] = $this->request->user()->id;
        return $this->cache()->apiResponse(function () use ($params) {
            $this->validate($params, OrderValidator::class . '.' . OrderValidator::READ);
            return $this->service->order_customers($params);
        });
    }

    /**
     * 取消订单/跟团游订单退款
     * @return mixed
     */
    public function cancel()
    {
        $params = $this->request->post();
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, OrderValidator::class . '.' . OrderValidator::CANCEL);
            return $this->service->cancel($params);
        });
    }

}