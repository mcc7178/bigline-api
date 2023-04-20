<?php
/**
 * Description : 支付服务
 * Author      : Kobin
 * CreateTime  : 2021/8/24 下午3:43
 */

namespace app\api\service\payment;

use app\api\lib\BizException;
use app\api\model\group\OrderModel;
use app\api\model\order\OrderOnlinePayModel;
use app\facade\Redis;
use comm\constant\CK;
use comm\model\independent\IndependentOrder;

class OnlinePayRecordsService
{
    const ORDER_INDEPENDENT = 1;  // 自由行
    const ORDER_GROUP = 0;  // 跟团游

    const ORDER_TYPE_CLASS = [
        self::ORDER_GROUP => OrderModel::class,
        self::ORDER_INDEPENDENT => IndependentOrder::class,
    ];
    const ORDER_SN_PREFIX = [
        self::ORDER_GROUP => 'GP',
        self::ORDER_INDEPENDENT => "IP",
    ];

    const PAY_APP = [
        self::PAY_APP_ALI,
        self::PAY_APP_WECHAT,
        self::PAY_APP_PAYME,
        self::PAY_APP_PAYPAL,
    ];
    const PAY_APP_ALI = 'alipay';
    const PAY_APP_WECHAT = 'wechat';
    const PAY_APP_PAYME = 'payme';
    const PAY_APP_PAYPAL = 'paypal';

    private $year = '';
    private $prefix = '';

    private string $pay_app = '';
    private int $order_type = 1;


    protected array $params = [];
    protected array $orderData = [];

    protected $paySn = '';
    protected $payAmount = 0;
    protected $payDec = '';


    protected $config = [];


    /**
     * PaymentService constructor.
     * @param string $payApp in PAY_APP_
     * @param int $orderType in ORDER_
     */
    public function __construct($payApp, $orderType)
    {
        if (!in_array($payApp, self::PAY_APP)) {
            throw new \Error('payApp 必须为' . implode(',', self::PAY_APP) . '中之一');
        }
        if (!in_array($orderType, [self::ORDER_GROUP, self::ORDER_INDEPENDENT])) {
            throw new \Error('orderType 必须为1-自由行订单或2-跟团游');
        }

        $this->pay_app = $payApp;
        $this->order_type = $orderType;

    }

    /**
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function getInfo(array $params)
    {
        $this->params = $params;
        $online = $this->getOrderData();
        return [
            'paySn' => $params['order_type'] . ':' . $online['local_trade_no'],
            'payAmount' => $online['total_amount'],
            'payDsc' => $this->payDec,
        ];
    }

    /**
     * 处理订单数据  生成在线支付单
     * @return array
     * @throws \Exception
     */
    private function getOrderData()
    {
        if ($this->order_type == self::ORDER_INDEPENDENT) {
            $this->handleIndependentOrder();
        } else if ($this->order_type == self::ORDER_GROUP) {
            $this->handleGroupOrder();
        }
        if (!$this->payAmount) {
            BizException::throwException(22106);
        }

        return $this->createOnlinePay();
    }

    /**
     * 根据订单IDS计算出应付金额 以及支付描述
     * @throws \Exception
     */
    private function handleIndependentOrder()
    {
        $order = IndependentOrder::where('id', $this->params['order_id'])->findOrEmpty()->toArray();
        if (!empty($order)) {
            $this->checkStatus($order);
            $this->paySn = $order['order_num'];
            $this->payDec = '大航假期-自由行订单:' . $order['order_num'];
            // 订单费用-已支付
            $this->payAmount += ($order['real_amount'] - $order['payed']);
        }
    }

    /**
     * @param $order
     * @throws \Exception
     */
    private function checkStatus($order)
    {
        if ($order['status'] == IndependentOrder::STATUS_PAYED) {
            BizException::throwException(22002);
        }
        if (in_array($order['status'], [4, 5, 6, 7])) {
            BizException::throwException(22103);
        }
    }

    /**
     * 根据订单IDS计算出应付金额 以及支付描述
     * @throws \Exception
     */
    private function handleGroupOrder()
    {
        $order = OrderModel::where('id', $this->params['order_id'])->findOrEmpty()->toArray();
        if (!empty($order)) {
            $this->checkStatus($order);
            $this->paySn = $order['order_sn'];
            $this->payDec = '大航假期-跟团游订单:' . $order['order_sn'];
            // 订单费用-已支付
            $this->payAmount += (int)bcsub((string)$order['order_fee'], (string)$order['paid_fee']);
        }
    }


    /**
     * @return array
     */
    private function createOnlinePay()
    {
        $online = OrderOnlinePayModel::where('order_ids', $this->params['order_id'])
            ->where('type', $this->params['order_type'])
            ->where('trade_no', '')
            ->findOrEmpty();
        if (empty($online->toArray())) {
            $onlinePayData = [
                'trade_no' => '',
                'local_trade_no' => $this->paySn,
                'type' => $this->order_type,
                'total_amount' => $this->payAmount,
                'order_ids' => $this->params['order_id'],
                'pay_app' => $this->pay_app,
            ];
            $online = OrderOnlinePayModel::create($onlinePayData);
        } else {
            OrderOnlinePayModel::edit(['total_amount' => $this->payAmount], $online->id);
        }
        return $online->toArray();
    }
}