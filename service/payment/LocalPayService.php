<?php
/**
 * https://www.processon.com/view/link/612f2d92e0b34d3550fa8029
 * Description :  LOCAL_PAYMENT 余额 离线支付服务
 * Author      : Kobin
 * CreateTime  : 2021/9/1 下午2:36
 */

namespace app\api\service\payment;


use app\api\lib\BizException;
use app\api\model\finance\ApiReceivePaymentOrdersModel;
use app\api\model\group\OrderModel;
use app\api\model\member\MemberModel;
use app\api\model\member\Wallet;
use app\api\service\ApiServiceBase;
use comm\model\finance\ReceivePaymentOrdersModel;
use comm\model\independent\IndependentOrder;
use comm\model\order\WalletFlow;
use comm\model\system\CompanyOffLinePayModel;
use comm\service\EncryptionService;
use comm\service\Transaction;
use think\facade\Log;

class LocalPayService extends ApiServiceBase
{
    /**
     * @param $order_type
     * @param $order_id
     * @param $company_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function offline($order_type, $order_id, $company_id)
    {
        if ($order_type == 1) {
            $order = IndependentOrder::where('id', $order_id)
                ->field('create_at as create_time')
                ->findOrEmpty()->toArray();
        } else {
            $order = OrderModel::where('id', $order_id)
                ->field('create_time')
                ->findOrEmpty()->toArray();
        }
        if (empty($order)) {
            BizException::throwException(9000, '訂單信息不存在!');
        }
        $order['create_time'] = is_int($order['create_time']) ? $order['create_time'] : strtotime($order['create_time']);
        $t = $order['create_time'] + env('config.ORDER_AUTO_CANCEL_MINUTE') * 60 - time();
        return [
            'tips_time' => $t > 0 ? $t : 0,
            'tips_whatsapp' => env('config.whatsapp', '62844992'),
            'tips_tel_phone' => env('config.telphone', '21339468'),
            'data' => CompanyOffLinePayModel::where('status', 1)->where('company_id', $company_id)->select()->toArray()
        ];
    }

    /**
     * @param $currency_id
     * @return array
     */
    public function getUserBalance($currency_id)
    {
        $currency = config('system.currency');
        $member = MemberModel::get(['id' => $this->member_id]);
        $data = (new Wallet())->where('member_id', $this->member_id)
            ->where('currency', $currency_id)
            ->field(['currency', 'balance'])
            ->findOrEmpty()
            ->toArray();
        $data['symbol'] = $currency[$currency_id]['symbol'];
        if (empty($data)) {
            $data['balance'] = 0;
        } else {
            $data['balance'] = bcdiv((string)$data['balance'], (string)100);
        }
        $data['pwd_set'] = $member->pay_password == '' ? 0 : 1;
        return $data;
    }

    /**
     * https://www.processon.com/view/link/612f2d92e0b34d3550fa8029
     * 余额支付
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function BalancePay($params)
    {
        // 校验密码
        $this->checkPayPwd($params['password']);
        $this->checkBalance($params['amount'], $params['currency']);
        // 校验金额
        $params['amount'] = (int)bcmul((string)$params['amount'], (string)100);

        $order = $this->getOrder((int)$params['order_id'], (int)$params['order_type']);
        $transaction = new Transaction('finance', 'order');
        $transaction->start();
        try {
            // 用户钱包流水 用户钱包金额变更
            Wallet::newOutlay(
                $order['currency'], $this->member_id, $params['amount'], WalletFlow::ORIGIN_TYPE_INDEPENDENT,
                $order['id'], WalletFlow::TYPE_OUTLAY
            );
            $status = $order['status'];
            if ($order['remain'] - $params['amount'] > 0) {
                $status = 2; //部分支付
            } else if ($order['remain'] - $params['amount'] == 0) {
                $status = 3; //已支付
            }
            // 订单已支付金额变更 订单状态变更
            if ($params['order_type'] == 1) {
                IndependentOrder::edit(['status' => $status], $order['id']);
                (new IndependentOrder())->where('id', $params['order_id'])
                    ->inc('payed', intval($params['amount']))
                    ->update();
            } else if ($params['order_type'] == 0) {
                OrderModel::edit(['status' => $status], $order['id']);
                (new OrderModel())->where('id', $params['order_id'])
                    ->inc('paid_fee', intval($params['amount']))
                    ->update();
            }
            $transaction->commit();
        } catch (\Exception $exception) {
            $transaction->rollback();
            Log::error('付款失敗Balance:' . $exception->getFile() . $exception->getLine() . $exception->getMessage());
            BizException::throwException(9000, '付款失敗，未知錯誤:' . $exception->getMessage());
        }

        return [
            'remain' => (float)bcdiv((string)bcsub((string)$order['remain'], (string)$params['amount']), (string)100)
        ];
    }

    /**
     * @param $pwd
     * @throws \Exception
     */
    private function checkPayPwd($pwd)
    {
        $info = MemberModel::where('id', $this->member_id)
            ->where('pay_password', (new EncryptionService())->encrypt($pwd))
            ->field('id')->findOrEmpty()->toArray();
        if (empty($info)) {
            BizException::throwException(22101);
        }
    }

    private function checkBalance($toBePayed, $currency)
    {
        $wallet = $this->getUserBalance($currency);
        if ($toBePayed > $wallet['balance']) {
            BizException::throwException(50002, $wallet['balance']);
        }
    }

    /**
     * @param $id
     * @param $type
     * @return array
     */
    public function getOrder(int $id, int $type)
    {
        $ret = [
            'type' => (int)$type,
            'id' => (int)$id,
            'currency' => 0,
            'company_id' => 0,
            'amount' => 0,
            'payed' => 0,
            'remain' => 0,
            'status' => 0,
        ];
        if ($type == 1) {
            $order = IndependentOrder::field(['id', 'real_amount', 'payed', 'currency_id', 'status', 'company_id', 'member_id', 'branch_id'])
                ->where('id', $id)
                ->findOrEmpty()->toArray();
            if (!empty($order)) {
                $ret['currency'] = (int)$order['currency_id'];
                $ret['amount'] = (int)$order['real_amount'];
                $ret['payed'] = (int)$order['payed'];
                $ret['status'] = $order['status'];
                $ret['member_id'] = $order['member_id'];
                $ret['company_id'] = $order['company_id'];
                $ret['currency_id'] = $order['currency_id'];
                $ret['branch_id'] = $order['branch_id'];
                $ret['remain'] = (int)bcsub((string)$order['real_amount'], (string)$order['payed']);
            }
        } else if ($type == 0) {
            $order = OrderModel::field(['id', 'order_fee', 'paid_fee', 'currency_id', 'status', 'company_id', 'member_id', 'branch_id'])
                ->where('id', $id)
                ->findOrEmpty()->toArray();
            if (!empty($order)) {
                $ret['currency'] = (int)$order['currency_id'];
                $ret['amount'] = (int)$order['order_fee'];
                $ret['payed'] = (int)$order['paid_fee'];
                $ret['status'] = $order['status'];
                $ret['member_id'] = $order['member_id'];
                $ret['company_id'] = $order['company_id'];
                $ret['currency_id'] = $order['currency_id'];
                $ret['branch_id'] = $order['branch_id'];
                $ret['remain'] = (int)bcsub((string)$ret['amount'], (string)$order['paid_fee']);
            }
        }
        return $ret;
    }

    /**
     * @param $params
     * @return float[]
     */
    public function offLinePaySubmit($params): array
    {
        $params['amount'] = (int)bcmul((string)$params['amount'], (string)100);
        $order = $this->getOrder((int)$params['order_id'], (int)$params['order_type']);
        $tran = new Transaction('finance', 'order');
        $tran->start();
        try {
            // 处理订单信息
            $remain = 0;
            $balance = 0;
            $after = bcadd((string)$order['payed'], (string)$params['amount'], 0);
            $payed = $after > $order['amount'] ? $order['amount'] : $after;
            if ($after >= $order['amount']) {
                if ($params['order_type'] == 1) {
                    $status = IndependentOrder::STATUS_PAYED;
                } else {
                    $status = \comm\model\order\OrderModel::PAID_STATUS;
                }
                $balance = bcsub((string)$after, (string)$order['amount'], 0);
            } else {
                if ($params['order_type'] == 1) {
                    $status = IndependentOrder::STATUS_PART_PAYED;
                } else {
                    $status = \comm\model\order\OrderModel::PARTIAL_STATUS;
                }
                $remain = bcsub((string)$order['amount'], (string)$after, 0);
            }
            if ($params['order_type'] == 1) {
                IndependentOrder::edit([
                    'payed' => $payed,
                    'status' => $status,
                ], $order['id']);
                // 收款单 origin_type
                $paymentOrderData = [
                    'origin_type' => ReceivePaymentOrdersModel::ORIGIN_TYPE_IND,
                ];
            } else if ($params['order_type'] == 0) {
                OrderModel::edit([
                    'paid_fee' => $payed,
                    'status' => $status,
                ], $order['id']);
                $paymentOrderData = [
                    'origin_type' => ReceivePaymentOrdersModel::ORIGIN_TYPE_GRP,
                ];
            } else {
                BizException::throwException(9005, 'Order_Type Invalid');
            }
            // 收款单
            $this->createReceivePaymentOrder(
                array_merge($paymentOrderData,
                    [
                        'company_id' => $order['company_id'],
                        'currency_id' => $order['currency_id'],
                        'member_id' => $order['member_id'],
                        'type' => substr($params['type_method'], 0, 1),
                        'type_method' => $params['type_method'],
                        'amount' => $params['amount'],
                        'balance' => $balance,
                        'third_record_id' => $params['third_record_id'],
                        'payment_time' => strtotime($params['payment_time']),
                        'origin_id' => $order['id'],
                        'branch_id' => $order['branch_id'],
                        'origin_fee_type' => ReceivePaymentOrdersModel::ORIGIN_FEE_TYPE_INDEX_ORDER,
                        'add_type' => ReceivePaymentOrdersModel::ADD_TYPE_USER_ADD,
                        'attachment' => $params['attachment'],
                        'remark' => $params['remark'],
                    ]
                )
            );
            $tran->commit();
        } catch (\Exception $e) {
            $tran->rollback();
            BizException::throwException('9005', '支付异常:' . $e->getLine() . $e->getMessage());
        }

        return [
            'remain' => $remain / 100
        ];
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
                'third_record_id' => $data['third_record_id'],
                'add_type' => $data['add_type']
            ]
        )->findOrEmpty()->toArray();
        if (empty($r)) {
            // 创建收款单 暂不清楚是否要自动确认以及用户创建流水
            ApiReceivePaymentOrdersModel::create($data);
        } else {
            BizException::throwException(50001, '該類型的流水號已存在，請確認後重試.');
//            ApiReceivePaymentOrdersModel::edit($data, $r['id']);
        }

    }

}