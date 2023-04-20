<?php

namespace app\api\service;

use app\admin\service\OrderService;
use app\facade\Redis;
use comm\constant\CK;
use comm\constant\CN;
use comm\model\independent\IndependentOrder;
use comm\model\order\OrderModel;
use think\Model;

class OrderNumberService
{
    private $default = 1;
    private $type = 1;
    private $prefix = '';
    private $orderNum = '';
    private $year = '';


    /**
     * OrderNumberService constructor.
     */
    public function __construct()
    {

    }

    /**
     * @param $type
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderNumber($type): string
    {
        $this->type = $type;
        $this->year = substr(date('Y'), -2);
        $this->setPrefix();
        $this->setNumber();
        return $this->prefix . $this->year . str_pad($this->orderNum, 5, "0", STR_PAD_LEFT);
    }

    private function setPrefix()
    {
        $this->prefix = CN::ORDER_PREFIX[$this->type];
    }

    /**
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function setNumber()
    {
        $key = CK::ORDER_NUMBER . $this->type . ':' . $this->year;
        if (!Redis::exists($key)) {
            // 可能被清掉 获取最近的订单号重置
            $number = $this->default;
            $fields = [
                OrderService::T_TYPE_GROUP => 'order_sn',
                OrderService::T_TYPE_INDEPENDENT => 'order_num'
            ];
            if ($this->type == OrderService::T_TYPE_GROUP) {
                $order = (new OrderModel())
                    ->where('order_sn', 'like', $this->prefix . $this->year . '%')
                    ->order('id', 'desc')->limit(1)
                    ->field('order_sn')
                    ->select()->toArray();
            } else {
                $order = (new IndependentOrder())
                    ->where('order_num', 'like', $this->prefix . $this->year . '%')
                    ->order('id', 'desc')
                    ->limit(1)
                    ->field('order_num')
                    ->select()->toArray();
            }
            if (count($order)) {
                if (strpos($order[0][$fields[$this->type]], $this->prefix) !== false) {
                    $number = intval(str_replace($this->prefix . $this->year, '', $order[0][$fields[$this->type]]));
                }
            }
            Redis::set($key, $number + 1);
        }
        Redis::incr($key);
        $this->orderNum = Redis::get($key);
    }

}