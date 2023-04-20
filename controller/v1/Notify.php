<?php
/**
 * Description : 支付回调
 * Author      : Kobin
 * CreateTime  : 2021/8/24 下午3:32
 */

namespace app\api\controller\v1;


use app\api\controller\Controller;
use app\api\model\finance\ApiReceivePaymentOrdersModel;
use app\api\model\group\OrderModel;
use app\api\model\member\MemberModel;
use app\api\model\order\OrderOnlinePayModel;
use app\api\service\payment\OnlinePayRecordsService;
use comm\model\finance\ReceivePaymentOrdersModel;
use comm\model\independent\IndependentOrder;
use comm\service\Transaction;
use think\facade\Log;
use think\Request;
use app\api\service\payment\PayPal;

class Notify extends Controller
{
    /**
     * 微信支付通知
     * @return string
     */
    public function wechat_notify()
    {
        try {
            $data = $this->handleWePayEzData();
            if ($data['status'] == 0) {
                if ($data['result_code'] == 0) {
                    $this->handleNotify([
                        'pay_result' => $data['pay_result'],// 支付结果 0-成功  其他-失败
                        'transaction_id' => $data['transaction_id'],// 平台流水号
                        'out_transaction_id' => $data['out_transaction_id'],// 第三方流水号
                        'out_trade_no' => $data['out_trade_no'],// '类型:订单号'
                        'total_fee' => $data['total_fee'],// 支付金额(单位:分)
                        'fee_type' => $data['fee_type'],// 貨幣類型，符合 ISO 4217 標準的三位字母代 碼，默認港幣：HKD
                        'type' => ReceivePaymentOrdersModel::TYPE_WEPAYEZ, // type
                        'type_method' => ReceivePaymentOrdersModel::SWIFT_PASS_WX_PAY_TYPE, // type_method
                    ]);
                } else {
                    Log::error('Error:' . $data['result_code']);
                }
            } else {
                Log::error('StatusFailed:' . $data['status']);
            }
        } catch (\Exception $e) {
            Log::error('wechat_notify:' . $e->getMessage());
            return 'fail';
        }
        return 'success';
    }

    /**
     * 支付宝支付通知
     * @return string
     */
    public function alipay_notify()
    {
        try {
            $data = $this->handleWePayEzData();
            if ($data['status'] == 0) {
                if ($data['result_code'] == 0) {
                    $this->handleNotify(
                        [
                            'pay_result' => $data['pay_result'],// 支付结果 0-成功  其他-失败
                            'transaction_id' => $data['transaction_id'],// 平台流水号
                            'out_transaction_id' => $data['out_transaction_id'],// 第三方流水号
                            'out_trade_no' => $data['out_trade_no'],// '类型:订单号'
                            'total_fee' => $data['total_fee'],// 支付金额(单位:分)
                            'fee_type' => $data['fee_type'],// 貨幣類型，符合 ISO 4217 標準的三位字母代 碼，默認港幣：HKD
                            'type' => ReceivePaymentOrdersModel::TYPE_WEPAYEZ, // type
                            'type_method' => ReceivePaymentOrdersModel::SWIFT_PASS_ALI_PAY_TYPE, // type_method
                        ]
                    );
                } else {
                    Log::write('alipay_notifyError:' . $data['result_code']);
                }
            } else {
                Log::write('alipay_notifyStatusFailed:' . $data['status']);
            }
        } catch (\Exception $e) {
            Log::write('alipay_notify:' . $e->getMessage());
            return 'fail';
        }

        return 'success';
    }

    /**
     * TODO
     * 支付宝支付回调
     * @param Request $request
     * @return int
     */
    public function alipay_return(Request $request)
    {
        return 1;
    }

    /**
     * @return mixed
     */
    private function handleWePayEzData()
    {
        $xml = $this->request->post();
        if (empty($xml)) {
            $xml = file_get_contents("php://input");
        }
        $data = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        Log::write('wePayEz_notify_array:' . json_encode($data));
        return $data;
    }

    /**
     * TODO
     * Payme支付回调
     * @param Request $request
     * @return int
     */
    public function payme_notify(Request $request)
    {
        return 1;
    }

    /**
     * paypal支付回調
     * @param \think\Request $request
     * @return false|string
     */
    public function paypal_notify(Request $request)
    {
        $get = $request->get();
        Log::error("paypal回调参数:" . json_encode($get, JSON_UNESCAPED_UNICODE));
        $data = (new PayPal(OnlinePayRecordsService::PAY_APP_PAYPAL))->requestPaymentCallBack($get);
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param $data
     * @return string
     * @throws \Exception
     */
    private function handleNotify($data)
    {
        $transaction = new Transaction('finance', 'order');
        $transaction->start();
        try {
            // 处理在线支付记录&处理订单信息
            // 用$data['out_trade_no']查询订单信息
            $trade_no = explode(':', $data['out_trade_no']);
            if (count($trade_no) != 2) {
                return 'fail';
            }
            $order_type = $trade_no[0];
            $order_no = $trade_no[1];
            // 支付成功则更新订单信息以及创建收款单
            if ($data['pay_result'] == 0) {
                // 处理订单信息
                if ($order_type == 1) {
                    $order = IndependentOrder::where('order_num', $order_no)->findOrEmpty();
                    $order->payed = $data['total_fee'];
//                    $order->payed = $order->real_amount;
                    $order->status = IndependentOrder::STATUS_PAYED;
                    $order->save();
                    // 收款单 origin_type
                    $paymentOrderData = [
                        'origin_type' => ReceivePaymentOrdersModel::ORIGIN_TYPE_IND,
                    ];
                } else if ($order_type == 2) {
                    $order = OrderModel::where('order_sn', $order_no)->findOrEmpty();
//                    $order->paid_fee = $order->order_fee;
                    $order->paid_fee = $data['total_fee'];
                    $order->status = \comm\model\order\OrderModel::PAID_STATUS;
                    $order->save();
                    // 收款单 origin_type
                    $paymentOrderData = [
                        'origin_type' => ReceivePaymentOrdersModel::ORIGIN_TYPE_IND,
                    ];
                } else {
                    throw new \Exception('type invalid');
                }
                // 收款单
                $this->createReceivePaymentOrder(
                    array_merge($paymentOrderData,
                        [
                            'company_id' => $order->company_id,
                            'currency_id' => $order->currency_id,
                            'member_id' => $order->member_id,
                            'type' => $data['type'],
                            'type_method' => $data['type_method'],
                            'amount' => $data['total_fee'],
                            'balance' => 0,
                            'third_record_id' => $data['transaction_id'],
                            'payment_time' => time(),
                            'origin_id' => $order->id,
                            'origin_type' => $order_type,
                            'branch_id' => $order->branch_id,
                            'origin_fee_type' => ReceivePaymentOrdersModel::ORIGIN_FEE_TYPE_INDEX_ORDER,
                        ]
                    )
                );
            }

            $transaction->commit();

        } catch (\Exception $exception) {
            $transaction->rollback();
            Log::info($exception->getFile() . $exception->getLine() . $exception->getMessage());
            throw $exception;
        }
    }


    /**
     * @param $data
     */
    private function createReceivePaymentOrder($data)
    {
        $r = ApiReceivePaymentOrdersModel::where(
            [
                'origin_fee_type' => $data['origin_fee_type'],
                'origin_id' => $data['origin_id'],
                'origin_type' => $data['origin_type'],
                'third_record_id' => $data['third_record_id']
            ]
        )->findOrEmpty()->toArray();
        if (empty($r)) {
            // 创建收款单 暂不清楚是否要自动确认以及用户创建流水
            ApiReceivePaymentOrdersModel::create($data);
        } else {
            ApiReceivePaymentOrdersModel::edit($data, $r['id']);
        }

    }


}