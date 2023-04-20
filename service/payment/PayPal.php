<?php
/**
 * Description :
 * Author      : Kobin
 * CreateTime  : 2021/8/26 上午11:56
 */

namespace app\api\service\payment;

use app\api\model\order\OrderOnlinePayModel;
use comm\model\finance\FinanaceJournalMiddleModel;
use comm\model\finance\JournalOrderModel;
use comm\model\finance\ReceivePaymentOrdersModel;
use comm\model\independent\IndependentOrder;
use comm\model\order\OrderModel;
use comm\model\system\SystemConfig;
use PayPal\Api\Amount;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;
use think\Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * TODO
 * Class PayPal
 * @package app\api\service\payment
 */
class PayPal extends BasePayment
{
    /**
     * 獲取Paypal授權地址
     * @param array $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getPaypalUrl(array $params)
    {
        $id = $params['order_id'];
        $type = $params['order_type'];

        //paypal不支持RMB支付,所以先將貨幣轉換為港幣
        $row = $this->get_order_info($id, $type);
        $rate = self::getExchangeRate($row['currency_id'], 2);
        if ($type == 1) {
            $amount = floor((float)bcmul($row['amount'], $rate));
            $currency_code = 'HKD';
            $tmp_data = [
                'paypal_email' => $this->config['paypal_email'],  //Paypal收款電郵
                'currency_code' => $currency_code,       //結算貨幣
                'item_name' => '',   //商品名稱
                'item_no' => '',       //團號
                'quantity' => '1',          //數量
                'invoice_no' => $row['order_num'],    //單號
                'item_amount' => $amount * 100,  //金額
                'tour_date' => '',  //出發日期
            ];
            foreach ($row['items'] as $value) {
                $json = json_decode($value['snapshot'], true);
                $tmp_data['item_name'] = $json['name'];
                if ($value['type'] == 9) {
                    $tmp_data['item_name'] = $json['name_tc'] ?? '';
                }
                $tmp_data['quantity'] = $value['qty'];
            }
        } else {
            $currency_code = 'HKD';
            $order_fee = floor((float)bcmul($row['order_fee'], $rate));
            $tmp_data = [
                'paypal_email' => $this->config['paypal_email'],  //Paypal收款電郵
                'currency_code' => $currency_code,       //結算貨幣
                'item_name' => $row['release']['base']['product_name'],   //商品名稱
                'item_no' => $row['release']['base']['product_code'],       //團號
                'quantity' => '1',          //數量
                'invoice_no' => $row['order_sn'],    //單號
                'item_amount' => $order_fee,  //金額
                'tour_date' => $row['release']['tour_date'],  //出發日期
            ];
        }
        return $this->requestPayment($tmp_data, $type);
    }

    public function requestPayment($params, $type)
    {
        $paypal_email = $params['paypal_email']; //it@bigline.hk
        $item_name = $params['item_name'] ?? '';     //C28003C 龍門《尚天然溫泉度假酒店》 任浸高山蘇打養生溫泉 黃金沙姜雞糯米珍珠骨 純玩2天
        $currency_code = $params['currency_code'];//HKD
        $item_no = $params['item_no'] ?? '';     //C28003C
        $quantity = $params['quantity'] ?? 1;     //1
        $invoice_no = $params['invoice_no'] ?? '';   //SP1800014
        $item_amount = $params['item_amount'];  //0.1
        $tour_date = $params['tour_date'] ?? '';    // 2018-09-18 出發日期
        $return_url = $this->config['return_url'];

        $apiContext = $this->getApiContext();
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');

        //set item
        $item = new Item();
        $item->setName($item_name)
            ->setCurrency($currency_code)
            ->setQuantity($quantity)
            ->setSku($item_no) // Similar to `item_number` in Classic API
            ->setPrice($item_amount);
        $itemList = new ItemList();
        $itemList->setItems(array($item));

        //set amount
        $amount = new Amount();
        $amount->setTotal($item_amount)->setCurrency($currency_code);
        $transaction = new Transaction();

        $setItemName = "訂單編號:" . $invoice_no;
        if ($tour_date) {
            $setItemName .= "，出發日期:" . $tour_date;
        }
        $transaction->setAmount($amount)->setItemList($itemList)->setDescription($setItemName)->setInvoiceNumber($invoice_no);
        //set return url
        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($return_url . "?success=true&order_sn=" . $invoice_no . "&type=" . $type)
            ->setCancelUrl($return_url . "?success=false&order_sn=" . $invoice_no . "&type=" . $type);

        $payment = new Payment();
        $payment->setIntent('sale')
            ->setPayer($payer)
            ->setTransactions(array($transaction))
            ->setRedirectUrls($redirectUrls);

        try {
            $payment->create($apiContext);
            return ['code' => 200, 'message' => '請求成功', 'url' => $payment->getApprovalLink()];
        } catch (PayPalConnectionException $ex) {
            return ['code' => 500, 'message' => $ex->getMessage()];
        }
    }

    /**
     * 支付回調
     * @param $paymentId
     * @param $PayerID
     * @return array
     */
    public function requestPaymentCallBack($params)
    {
        $paymentId = $params['paymentId'] ?? '';
        $PayerID = $params['PayerID'] ?? '';
        $type = $params['type'] ?? 0;
        $order_sn = $params['order_sn'] ?? '';

        Log::warning('接受paypal支付回調,paymentId:' . $paymentId . ',payerId:' . $PayerID);
        $apiContext = $this->getApiContext();
        $payment = new Payment();
        $payment = $payment->get($paymentId, $apiContext);
        $execution = new PaymentExecution();
        $execution->setPayerId($PayerID);

        try {
            Log::warning('回調成功,請求支付數據');

            // Execute the payment
            $result = $payment->execute($execution, $apiContext);
            if ($payment->getState() == 'approved') {
                $transactions = $result->getTransactions();
                $transaction = $transactions[0];
                Log::warning('請求支付數據成功，處理訂單數據' . json_encode([$order_sn, $type, $transaction->related_resources[0]->sale->id, $transaction->amount->total]));

                //處理訂單數據
                $res = $this->order_handle($order_sn, $type, $paymentId);
                if ($res['status'] == 0) {
                    return ['code' => 500, 'message' => '訂單數據處理失敗,' . $res['message'], 'order_sn' => $order_sn, 'type' => $type];
                }
                return [
                    'status' => 200,
                    'msg' => '付款成功',
                    'order_sn' => $order_sn,
                    'type' => $type,
                    'refund_id' => $transaction->related_resources[0]->sale->id,
                    'amount' => $transaction->amount->total,
                ];
            } else {
                return ['code' => 500, 'message' => '用戶尚未授權支付,STATUS:' . $payment->getState(), 'order_sn' => $order_sn, 'type' => $type];
            }
        } catch (Exception $ex) {
            return ['code' => 500, 'message' => '請求失敗,' . $ex->getMessage()];
        }
    }

    /**
     * 訂單處理
     * @param $order_sn
     * @param $type
     * @param $payment_id
     * @return mixed
     */
    private function order_handle($order_sn, $type, $payment_id)
    {
        try {
            Log::warning(['order_sn' => $order_sn, 'type' => $type, 'payment_id' => $payment_id]);
            Db::startTrans();
            $payment_id = explode('-', $payment_id);
            if (empty($payment_id[1])) {
                throw new Exception('付款編號有誤');
            }
            if ($type == 0) {
                $field = 'id,status,order_fee,currency_id,branch_id,member_id,company_id';
                $orderModel = OrderModel::where('order_sn', $order_sn)->field($field)->findOrFail();
                $order_fee = $orderModel->order_fee;
            } else {
                $field = 'id,status,real_amount,currency_id,branch_id,member_id,company_id';
                $orderModel = IndependentOrder::where('order_num', $order_sn)->field($field)->findOrFail();
                $order_fee = $orderModel->real_amount * 100;
            }
            if ($orderModel->status == 3) {
                throw new Exception('訂單已支付,無需處理');
            }
            if ($orderModel->status > 3) {
                throw new Exception('訂單已取消/退款');
            }

            Log::warning('修改訂單數據');
            //修改訂單數據
            if ($type == 0) {
                $orderModel->save(['status' => 3, 'paid_fee' => $order_fee]);
            } else {
                $orderModel->save(['status' => 3, 'payed' => $order_fee]);
            }

            //新增在線支付記錄
            OrderOnlinePayModel::create([
                'trade_no' => $payment_id[1],
                'local_trade_no' => $order_sn,
                'type' => $type == 0 ? 2 : 1,
                'total_amount' => $order_fee,
                'order_ids' => $orderModel->id,
                'pay_app' => 'paypal',
                'status' => 1,
            ]);
            Log::warning('新增在線支付記錄');

            //新增收款單數據
            $receivePaymentOrdersModel = new ReceivePaymentOrdersModel();
            $receivePaymentOrdersModel->save([
                'company_id' => $orderModel->company_id,
                'currency_id' => $orderModel->currency_id,
                'member_id' => $orderModel->member_id,
                'type' => 5,
                'type_method' => 503,
                'amount' => $order_fee,
                'third_record_id' => $payment_id[1],
                'payment_time' => time(),
                'branch_id' => $orderModel->branch_id,
                'origin_type' => $type == 0 ? 2 : 1,
                'origin_id' => $orderModel->id,
            ]);
            Log::warning('新增收款單數據');

            //新增流水賬表
            $title = "APP" . ($type == 0 ? '跟團遊' : '自由行') . "訂單收款";
            Log::warning($title);
            $journalorderModel = new JournalOrderModel();
            $res = $journalorderModel->save([
                'company_id' => $orderModel->company_id,
                'create_staff_id' => 0,
                'order_type' => 1,
                'order_id' => $orderModel->id,
                'currency_id' => $orderModel->currency_id,
                'fee' => $order_fee,
                'title' => $title,
                'remark' => '',
                'confirm_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            if ($res === false) {
                throw new Exception('新增流水賬表數據失敗');
            }
            Log::warning('新增流水賬表');

            //新增流水賬中間表
            $middleModel = new FinanaceJournalMiddleModel();
            $res = $middleModel->save([
                'payment_order_id' => $receivePaymentOrdersModel->id,
                'payment_type' => 2,
                'journal_id' => $journalorderModel->id,
            ]);
            if ($res === false) {
                throw new Exception('新增流水賬中間表數據失敗');
            }
            Log::warning('新增流水賬中間表');

            Db::commit();
            return ['status' => 1, 'message' => '訂單數據處理成功'];
        } catch (Exception $exception) {
            Db::rollback();
            return ['status' => 0, 'message' => $exception->getMessage()];
        }
    }

    /**
     * 獲取訂單基礎信息
     * @param $order_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function get_order_info($order_id, $type)
    {
        //自由行:1;跟团游:0
        if ($type == 1) {
            return IndependentOrder::with([
                'items' => function ($query) {
                    $query->field('id,order_id,qty,snapshot');
                }
            ])
                ->field('id,order_num,status,currency_id,amount')
                ->findOrFail($order_id)
                ->toArray();
        } else {
            return OrderModel::with([
                'release' => function ($query) {
                    $query->with(['base' => function ($query) {
                        $query->field('product_id,pid,product_name,product_code');
                    }])->field('id,product_base_id,tour_date');
                }
            ])->field('id,status,order_sn,order_fee,currency_id,product_release_id')
                ->findOrFail($order_id)
                ->toArray();
        }
    }

    private function getApiContext()
    {
        $apiContext = new ApiContext(
            new OAuthTokenCredential(
                $this->config['ClientID'],     // ClientID
                $this->config['ClientSecret']      // ClientSecret
            )
        );
        $apiContext->setConfig(['mode' => $this->config['use_sandbox'] ? 'sandbox' : 'live']);
        return $apiContext;
    }

    /**
     * 获取汇率
     *
     * @param $base_currency
     * @param $target_currency
     * @return false|float
     */
    public static function getExchangeRate($base_currency, $target_currency)
    {
        if ((int)$base_currency === (int)$target_currency) {
            return 1;
        }
        $exchange_rate = SystemConfig::getExchangeRate('hk_currency');
        return $exchange_rate[$base_currency][$target_currency];
    }
}