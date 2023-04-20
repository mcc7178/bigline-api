<?php

namespace app\api\service;

use app\admin\controller\order\GroupTour;
use app\api\lib\BizException;
use app\api\model\independent\IndependentCombination;
use app\api\model\product\ProductBaseModel;
use comm\model\finance\ReceivePaymentOrdersModel;
use comm\model\independent\IndependentOrder;
use comm\model\independent\IndependentOrderCustomer;
use comm\model\independent\IndependentOrderHistory;
use comm\model\independent\IndependentOrderItems;
use comm\model\member\MemberManagementModel;
use comm\model\order\CustomerCredentialsModel;
use comm\model\order\OrderCustomerModel;
use comm\model\order\OrderModel;
use comm\model\order\Room;
use comm\model\product\ProductMeetingModel;
use comm\model\product\ProductRegister;
use comm\model\product\ProductReleaseModel;
use comm\model\supplier\SupplierPackageModel;
use comm\model\system\SystemConfig;
use comm\model\SystemAdmin;
use comm\service\Transaction;
use think\Exception;
use think\Model;

/**
 * 订单服务
 * Class OrdersService
 * @package app\api\service
 */
class OrderService extends ApiServiceBase
{
    /**
     * 页面统计数据
     * @param array $params
     * @return array
     */
    public function stat(array $params)
    {
        $memberService = new MemberService($this->company_id, $this->member_id, (int)$this->device_id);
        return ($memberService)->orderStat($this->device_id, $params['type'] ?? 0, $params['status'] ?? 0);
    }

    /**
     * 订单列表
     * @param array $params
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function list(array $params): array
    {
        $type = $params['type'] ?? 0;
        $where = [
            ['member_id', '=', $this->member_id],
            //['type', '=', $this->device_id],
        ];

        if (!empty($params['status'])) {
            switch ($params['status']) {
                //待支付
                case 1:
                    $where[] = ['status', 'in', [1, 2]];
                    break;
                //待出行
                case 2:
                    $where[] = ['status', '=', 3];
                    break;
                //待评价
                case 3:
                    $where[] = ['status', '=', -1];
                    break;
                //取消/退款
                case 4:
                    $where[] = ['status', 'in', [4, 5, 6, 7]];
                    break;
                //已出行
                case 8:
                    $where[] = ['status', 'in', [8, 9]];
                    break;
            }
        }
        if ($type == 0) {
            return $this->getGroupOrderList($params, $where);
        } else {
            return $this->getIndependentOrderList($params, $where);
        }
    }

    /**
     * 跟团游订单列表
     * @param array $params
     * @param array $where
     * @return array
     * @throws \think\db\exception\DbException
     */
    private function getGroupOrderList(array $params, array $where): array
    {
        $Model = OrderModel::with(['release' => function ($query) {
            $query->with(['base' => function ($query) {
                $query->field('product_id,pid,product_name,product_code,total_days');
            }])->field('id,product_base_id,tour_status,tour_date,tour_finish_date,car_no');
        }])->where($where);
        if (!empty($params['name'])) {
            $baseIds = ProductBaseModel::whereLike('product_name', "%{$params['name']}%")->column('product_id');
            if ($baseIds) {
                $releaseIds = ProductReleaseModel::where('product_base_id', 'in', $baseIds)->column('id');
                if ($releaseIds) {
                    $Model = $Model->where(
                        fn($query) => $query->whereLike('order_sn', "%{$params['name']}%")->whereOr('product_release_id', 'in', $releaseIds)
                    );
                }
            } else {
                $Model = $Model->where('order_sn', 'like', "%{$params['name']}%");
            }
        }
        $Model = $Model->field('id,order_sn,status,order_fee,currency_id,create_time,product_release_id')
            ->order(['status' => 'asc', 'create_time' => 'desc'])
            ->paginate($params['limit'] ?? 10)
            ->each(function ($item) {
                if ($item['status'] == 1) {
                    $t = strtotime($item['create_time']) + env('config.ORDER_AUTO_CANCEL_MINUTE') * 60 - time();
                    $item['tips_time'] = $t > 0 ? $t : 0;
                } else {
                    $item['tips_time'] = 0;
                }
                $item['currency'] = $item['currency_id'] == 1 ? '¥' : '$';
                $item['status_desc'] = OrderModel::STATUS[$item['status']] ?? '';
            });
        return $Model->toArray();
    }

    /**
     * 自由行订单列表
     * @param array $params
     * @param array $where
     * @return array
     * @throws \think\db\exception\DbException
     */
    private function getIndependentOrderList(array $params, array $where): array
    {
        $where = [];
        if (!empty($params['status'])) {
            switch ($params['status']) {
                //待支付
                case 1:
                    $where[] = ['o.status', 'in', [1, 2]];
                    break;
                //待出行
                case 2:
                    $where[] = ['o.status', '=', 3];
                    break;
                //待评价
                case 3:
                    $where[] = ['o.status', '=', -1];
                    break;
                //取消/退款
                case 4:
                    $where[] = ['o.status', '>', 3];
                    break;
                //已出行
                case 8:
                    $where[] = ['o.status', 'in', [8, 9]];
                    break;
            }
        }
        try {
            $model = (new IndependentOrder())->alias('o')
                //->where('o.type', $this->device_id)
                ->where('o.member_id', $this->member_id);
            if (!empty($params['name'])) {
                $model = $model->leftjoin([full_table_name(new IndependentOrderItems()) => 'i'], 'i.order_id = o.id AND i.type=9')
                    ->leftjoin([full_table_name(new IndependentCombination()) => 'c'], 'i.item_id = c.id')
                    ->where(
                        fn($query) => $query->whereLike('c.name_tc', "%{$params['name']}%")->whereOr('o.order_num', 'like', "%{$params['name']}%")
                    );
            }
            return $model->with([
                'items' => function ($query) {
                    $query->field('id,order_id,type,item_id,qty,snapshot');
                },
                'details' => function ($query) {
                    $query->field('id,order_id,type,date_in,date_out,qty');
                }
            ])
                ->where($where)
                ->field('o.id,o.order_num,o.status,o.currency_id,o.real_amount,o.create_at')
                ->order(['o.status' => 'asc', 'o.create_at' => 'desc'])
                ->paginate($params['limit'] ?? 10)
                ->each(function ($item) {
//                    return $this->handleIO($item);
                    if ($item['status'] == 1) {
                        $t = strtotime($item['create_at']) + env('config.ORDER_AUTO_CANCEL_MINUTE') * 60 - time();
                        $item['tips_time'] = $t > 0 ? $t : 0;
                    } else {
                        $item['tips_time'] = 0;
                    }
                    $item['name_tc'] = '';
                    $item['product_id'] = 0;
                    $item['currency'] = $item['currency_id'] == 1 ? '¥' : '$';
                    $item['status_desc'] = IndependentOrder::STATUS_STR[$item['status']] ?? '';
                    foreach ($item['items'] as $key => $value) {
                        $json = json_decode($value['snapshot'], true);
                        $item['product_name'] = $json['name'];
                        if ($value['type'] == 9) {
                            $item['product_name'] = $json['name_tc'] ?? '';
                            $item['product_id'] = $value['item_id'];
                        }
                        $item['useqty'] = $json['use_num'] ?? 0;
                        $item['days'] = $json['days'] ?? 0;
                        $item['type'] = $value['type'];
                        $item['qty'] = $value['qty'];
                        $item['supplier_name'] = $json['supplierSchedule']['name'] ?? '';
                    }
                    $dateIn = $dateOut = [];
                    foreach ($item['details'] as $value) {
                        $dateIn[] = $value['date_in'];
                        $dateOut[] = $value['date_out'];
                    }
                    $date_in = min($dateIn);
                    $date_out = min($dateOut);
                    $item['real_amount'] = (float)bcdiv((string)$item['real_amount'], (string)100);
                    $item['date_in'] = $date_in !== 0 ? date('Y-m-d', $date_in) : '';
                    $item['date_out'] = $date_out !== 0 ? date('Y-m-d', $date_out) : '';
                    unset($item['items'], $item['details']);
                })
                ->toArray();
        } catch (\Exception $e) {
            dd($e->getFile() . $e->getLine() . $e->getMessage());
        }

    }


    /**
     * 订单详情
     * @param $params
     * @return array|mixed
     * @throws \Exception
     */
    public function read($params): array
    {
        if ($params['type'] == 1) {
            $ret = IndependentOrder::with([
                'items' => function ($query) {
                    $query->field(['id', 'order_id', 'type', 'item_id', 'qty', 'snapshot']);
                },
                'details' => function ($query) {
                    $query->field(['id', 'order_id', 'traveler_type', 'type', 'item_id', 'date_in', 'date_out', 'qty', 'amount', 'snapshot']);
                },
                'staff' => function ($query) {
                    $query->field(['id', 'admin_id'])->with([
                        'admin' => function ($query) {
                            $query->field(['id', 'account']);
                        },
                    ]);
                },
            ])
                ->where('id', $params['id'])
                ->where('member_id', $params['member_id'])
                ->field([
                    'id', 'order_num', 'status', 'currency_id', 'real_amount', 'create_at', 'payed', 'refund', 'refund_procedure_fee', 'discount',
                    'contact_last_name', 'contact_first_name', 'contact_phone', 'staff_id', 'remark', 'cancel_reason as reason', 'currency_id'
                ])
                ->findOrEmpty()
                ->toArray();
            if (empty($ret)) {
                BizException::throwException(22104);
            }
            $ret = $this->handleIO($ret);
            $ret['payRecord'] = ReceivePaymentOrdersModel::where('origin_id', $params['id'])
                ->where(['origin_type' => ReceivePaymentOrdersModel::ORIGIN_TYPE_IND])
                ->field('id,type,type_method,amount,third_record_id,payment_time,add_type')
                ->select()
                ->toArray();
            $ret['userPay'] = ReceivePaymentOrdersModel::where('origin_id', $params['id'])
                ->where(['origin_type' => ReceivePaymentOrdersModel::ORIGIN_TYPE_IND])
                ->where(['add_type' => ReceivePaymentOrdersModel::ADD_TYPE_USER_ADD])
                ->field('id,type,type_method,amount,third_record_id,payment_time,add_type,attachment,confirm_status,remark')
                ->select()
                ->toArray();
            if (empty($ret['payRecord'])) {
                $ret['payRecord'] = null;
            }
            if (empty($ret['userPay'])) {
                $ret['userPay'] = null;
            }
        } else {
            $ret = OrderModel::with([
                'release' => function ($query) {
                    $query->with(['base' => function ($query) {
                        $query->with([
                            'schedule' => function ($query) {
                                $query->with(['package' => function ($query) {
                                    $query->with(['item' => function ($query) {
                                        $query->with(['index'])->field('id,supplier_package_id,type,index_id');
                                    }])->field('id,suppliertable_id,name');
                                }, 'supplier'])->field('id,product_base_id,type,supplier_id,target_id,index_id,package_id,days')->where('type', 'hotel');
                            },
                            'insurPlan' => function ($query) {
                                $query->field('id,name');
                            },
                        ])->field('product_id,pid,product_name,product_code,total_days,insur_plan_id,currency');
                    }])->field('id,product_base_id,tour_status,tour_date,tour_finish_date,member_fee,adult_fee,child_fee,baby_fee,car_no');
                },
                'staff' => function ($query) {
                    $query->field(['id', 'account']);
                },
                'payRecord' => function ($query) {
                    $query->field('id,currency_id,type,type_method,amount,third_record_id,payment_time,origin_id,add_type');
                },
                'userPay' => function ($query) {
                    $query->where(['add_type' => ReceivePaymentOrdersModel::ADD_TYPE_USER_ADD])
                        ->field('id,currency_id,type,type_method,amount,third_record_id,payment_time,origin_id,add_type,attachment,confirm_status,remark');
                },
                'customer' => function ($query) {
                    $query->field('id,order_id,phone,is_member,status');
                },
                'additionItem',
                'room' => function ($query) {
                    $query->field('order_id,days,package_id');
                }
            ])
                ->where('id', $params['id'])
                ->where('member_id', $params['member_id'])
                ->findOrEmpty()->toArray();
            if (empty($ret)) {
                BizException::throwException(22104);
            }
            if ($ret['status'] == 1) {
                $t = strtotime($ret['create_time']) + env('config.ORDER_AUTO_CANCEL_MINUTE') * 60 - time();
                $ret['tips_time'] = $t > 0 ? $t : 0;
            } else {
                $ret['tips_time'] = 0;
            }
            if (!empty($ret['staff'])) {
                $ret['staff'] = $ret['staff']['account'];
            } else {
                $ret['staff'] = '';
            }
            $ret['remain'] = (float)bcsub((string)$ret['order_fee'], (string)$ret['paid_fee']);
            $ret['currency'] = $ret['currency_id'] == 1 ? '¥' : '$';
            $ret['status_desc'] = OrderModel::STATUS[$ret['status']] ?? '';
            $ret['contact_name'] = $ret['contact_last_name'] . $ret['contact_first_name'];
            $ret['contact_phone'] = $ret['contact_way'];

            if (!empty($ret['release']['base']['schedule'])) {
                $room = array_column($ret['room'], NULL, 'days');
                foreach ($ret['release']['base']['schedule'] as $item) {
                    $ret['schedule'][] = [
                        'days' => $item['days'],
                        'project_name' => $item['supplier']['name'] ?? '',
                        'name' => SupplierPackageModel::where('id', $room[$item['days']]['package_id'])->value('name', ''),
                    ];
                }
            }
            $normal = $member = 0;
            foreach ($ret['customer'] as $item) {
                if ($item['is_member'] == 1) {
                    $member += 1;
                } else {
                    $normal += 1;
                }
            }
            $ret['normal_num'] = $normal;
            $ret['member_num'] = $member;
            $ret['insurance_num'] = OrderCustomerModel::where(['order_id' => $params['id'], 'is_insurance' => 1])->count();
            $ret['job_num'] = SystemAdmin::where('id', $ret['staff_id'])->value('account', '');
            unset($ret['contact_last_name'], $ret['contact_first_name'], $ret['contact_way'], $ret['release']['base']['schedule'], $ret['customer'], $ret['room']);
        }
        $ret['contact'] = [
            'tips_whatsapp' => env('config.whatsapp', '62844992'),
            'tips_tel_phone' => env('config.telphone', '21339468'),
        ];
        $ret['room_qty'] = Room::where('order_id', $params['id'])->order('days', 'asc')->value('occupied', 0);
        if ($ret['payRecord']) {
            foreach ($ret['payRecord'] as $key => $item) {
                $currency = $params['type'] == 1 ? $ret['currency_id'] : $ret['release']['base']['currency'];
                $ret['payRecord'][$key]['type'] = ReceivePaymentOrdersModel::PAYMENT_TYPE[$item['type']]['label'] ?? '';
                $ret['payRecord'][$key]['type_method'] = ReceivePaymentOrdersModel::PAYMENT_TYPE[$item['type']]['source_type'][$item['type_method']] ?? '';
                $ret['payRecord'][$key]['exchangeRate'] = self::getExchangeRate($item['currency_id'], $currency);
            }
        }
        return $ret;
    }

    /**
     * 处理自由行订单数据
     * @param $data
     * @return array
     */
    private function handleIO($data): array
    {
        !is_array($data) && $data = $data->toArray();
        $data['contact_name'] = $data['contact_last_name'] . $data['contact_first_name'];
        $data['real_amount'] = (float)bcdiv((string)$data['real_amount'], (string)100);
        $data['payed'] = (float)bcdiv((string)$data['payed'], (string)100);
        $data['refund'] = (float)bcdiv((string)$data['refund'], (string)100);
        $data['refund_procedure_fee'] = (float)bcdiv((string)$data['refund_procedure_fee'], (string)100);
        $data['discount'] = (float)bcdiv((string)$data['discount'], (string)100);
        $data['remain'] = (float)bcsub((string)$data['real_amount'], (string)$data['payed']);
        $data['name_tc'] = '';
        if (!empty($data['staff'])) {
            $data['staff'] = $data['staff']['admin']['account'];
        } else {
            $data['staff'] = '';
        }
        $data['name_tc'] = '';
        $data['currency'] = $data['currency_id'] == 1 ? '¥' : '$';
        $data['status_desc'] = IndependentOrder::STATUS_STR[$data['status']] ?? '';
        if (!empty($data['items'])) {
            foreach ($data['items'] as $key => $value) {
                $snapshot = json_decode($value['snapshot'], true);
                if ($value['type'] == 9) {
                    $data['product_name'] = $snapshot['name_tc'] ?? '';
                    $data['product_id'] = $value['item_id'];
                }
                $data['price'] = $snapshot['sale_amount'] ?? 0;
                if ($snapshot['discount_end'] > $data['create_at']) {
                    $data['discount_single'] = $snapshot['discount'] ?? 0;
                } else {
                    $data['discount_single'] = 0;
                }

                $data['useqty'] = $snapshot['use_num'] ?? 0;
                $data['days'] = $snapshot['days'] ?? 0;
                $data['type'] = $value['type'];
                $data['qty'] = $value['qty'];
                $data['supplier_name'] = $json['supplierSchedule']['name'] ?? '';
            }
        }
        $weekArray = array('周日', '周一', '周二', '周三', '周四', '周五', '周六');
        if (!empty($data['details'])) {
            $details = [];
            foreach ($data['details'] as $k => $item) {
                $nap = json_decode($item['snapshot'], true);
                $useqty = isset($nap['useqty']) ? $nap['useqty'] : 1;
                $num = $item['qty'] * $useqty;
                $amount = $item['amount'] / 100;
                $price = $amount / $item['qty'] / $useqty;
                $detail['id'] = [$item['id']];
                $detail['type'] = $item['type'];
                $detail['item_id'] = $item['item_id'];
                $date_out = empty($item['date_out']) ? $item['date_in'] : $item['date_out'];
                $detail['date_in_w'] = $item['date_in'] !== 0 ? $weekArray[date('w', $item['date_in'])] : '';
                $detail['date_in'] = date('Y-m-d', $item['date_in']);
                $detail['date_out_w'] = $date_out !== 0 ? $weekArray[date('w', $date_out)] : '';
                $detail['date_out'] = date('Y-m-d', $date_out);
                $detail['name'] = isset($nap['supplierSchedule']['name']) ? $nap['supplierSchedule']['name'] : (isset($nap['supplierTraffic']['name']) ? $nap['supplierTraffic']['name'] : '');
                $detail['item'] = isset($nap['name']) ? $nap['name'] : '';
                $detail['amount_adult'] = $item['traveler_type'] == 'adult' ? $amount : 0;
                $detail['amount_child'] = $item['traveler_type'] == 'child' ? $amount : 0;
                $detail['price_adult'] = $item['traveler_type'] == 'adult' ? $price : 0;
                $detail['price_child'] = $item['traveler_type'] == 'child' ? $price : 0;
                $detail['num_adult'] = $item['traveler_type'] == 'adult' ? $num : 0;
                $detail['num_child'] = $item['traveler_type'] == 'child' ? $num : 0;

                $key = md5($detail['type'] . '_' . $detail['item_id'] . '_' . $detail['date_in']);
                if (!isset($details[$key])) {
                    $details[$key] = $detail;
                } else {
                    if (!in_array($detail['id'], $details[$key]['id'])) {
                        $details[$key]['amount_adult'] += $detail['amount_adult'];
                        $details[$key]['amount_child'] += $detail['amount_child'];
                        $details[$key]['price_adult'] += $detail['price_adult'];
                        $details[$key]['price_child'] += $detail['price_child'];
                        $details[$key]['num_adult'] += $detail['num_adult'];
                        $details[$key]['num_child'] += $detail['num_child'];
                        array_push($details[$key]['id'], $item['id']);
                    }
                }
            }
            foreach ($details as &$item) {
                unset($item['id']);
            }
            $data['details'] = array_values($details);
        }

        $weekarray = array('周日', '周一', '周二', '周三', '周四', '周五', '周六');
        $date_in = min(array_column($data['details'], 'date_in'));
        $date_out = max(array_column($data['details'], 'date_out'));
        $data['date_in'] = min(array_column($data['details'], 'date_in'));
        $data['date_in_w'] = $date_in !== 0 ? $weekarray[date('w', strtotime($date_in))] : '';
        $data['date_out'] = max(array_column($data['details'], 'date_out'));
        $data['date_out_w'] = $date_out !== 0 ? $weekarray[date('w', strtotime($date_out))] : '';
        if ($data['status'] == 1) {
            $t = strtotime($data['create_at']) + env('config.ORDER_AUTO_CANCEL_MINUTE') * 60 - time();
            $data['tips_time'] = $t > 0 ? $t : 0;
        } else {
            $data['tips_time'] = 0;
        }
        unset($data['items'], $data['contact_last_name'], $data['contact_first_name']);
        return $data;
    }

    /**
     * 订单出行人信息
     * @param $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function order_customers($params): array
    {
        if ($params['type'] == 1) {
            $credentials = config('system.credentials');
            $credentials_code = array_column($credentials, 'code');
            $credentials = array_column($credentials, 'name', 'code');
            $ret = IndependentOrderCustomer::alias('oc')
                ->leftjoin(full_table_name(new IndependentOrder()) . ' o', 'oc.order_id=o.id')
                ->where('oc.order_id', $params['id'])
                ->where('o.member_id', $params['member_id'])
                ->field([
                    'oc.id', 'oc.order_id', 'oc.lastName_cn', 'oc.firstName_cn', 'oc.firstName_en', 'oc.lastName_en',
                    'oc.phone', 'oc.identity_id', 'oc.credentials'
                ])
                ->select()
                ->map(function (Model $item) use ($credentials, $credentials_code) {
                    $name = '';
                    if (!empty($item['lastName_cn']) && !empty($item['firstName_cn'])) {
                        $name .= $item['lastName_cn'] . $item['firstName_cn'];
                    }
                    if (!empty($item['firstName_en']) && !empty($item['firstName_en'])) {
                        $end = '';
                        if (!empty($name)) {
                            $name .= '(';
                            $end = ')';
                        }
                        $name .= $item['firstName_en'] . ' ' . $item['lastName_en'] . $end;
                    }

                    $item['name'] = $name;
                    if (in_array($item['credentials'], $credentials_code)) {
                        $item['credentials_name'] = $credentials[$item['credentials']];
                    } else {
                        $item['credentials_name'] = '證件';
                    }
                    $item['identity_id'] = empty($item['identity_id']) ? '' : $item['identity_id'];
                    $item['travel_start_location_name'] = '';
                    $item['type'] = 'adult';
                    $item['type_name'] = ProductRegister::AGE_TYPE[$item['type']];
                    $item['is_member'] = MemberManagementModel::getMemberStatus($item['phone'], $this->company_id)['member_status'] == 'registered' ? 1 : 0;
                    unset($item['lastName_cn'], $item['firstName_cn'], $item['firstName_en'], $item['lastName_en']);
                    return $item;
                })->toArray();
        } else {
            $ret = OrderCustomerModel::alias('c')
                ->leftJoin(full_table_name(new OrderModel()) . ' o', 'o.id = c.order_id')
                ->leftJoin(full_table_name(new CustomerCredentialsModel()) . ' cc', 'c.id = cc.order_customer_id and c.credential_type=cc.credentials')
                ->leftJoin(full_table_name(new ProductMeetingModel()) . ' pm', 'pm.id = c.travel_start_location_meeting')
                ->where('c.order_id', $params['id'])
                ->where('o.member_id', $params['member_id'])
                ->distinct(true)
                ->field(['c.id', 'c.order_id', 'c.chinese_last_name', 'c.chinese_first_name', 'c.english_first_name', 'c.english_last_name',
                    'c.is_member', 'c.travel_start_location_name', 'c.type', 'cc.credentials_num as identity_id', 'c.phone',
                    'pm.name as travel_start_location_meeting', 'pm.desc', 'cc.credentials'])
                ->select()
                ->map(function ($item) {

                    $name = '';
                    if (!empty($item['chinese_last_name']) || !empty($item['chinese_first_name'])) {
                        $name .= $item['chinese_last_name'] . $item['chinese_first_name'];
                    }
                    if (!empty($item['english_first_name']) || !empty($item['english_last_name'])) {
                        $end = '';
                        if (!empty($name)) {
                            $name .= '(';
                            $end = ')';
                        }
                        $name .= $item['english_first_name'] . ' ' . $item['english_last_name'] . $end;
                    }

                    $item['name'] = $name;
                    $item['type_name'] = ProductRegister::AGE_TYPE[$item['type']];
                    $item['identity_id'] = empty($item['identity_id']) ? '' : $item['identity_id'];
                    $item['travel_start_location_meeting'] = empty($item['travel_start_location_meeting']) ? '' : ($item['travel_start_location_meeting'] . ' ' . $item['desc']);
                    $item['member_config'] = config('system.member_config');
                    $item['member_status'] = (MemberManagementModel::getMemberStatus($item['phone'], $this->company_id))['member_status'];
                    unset($item['chinese_last_name'], $item['chinese_first_name'], $item['english_first_name'], $item['english_last_name']);
                    return $item;
                })
                ->toArray();
        }

        if (empty($ret)) {
            BizException::throwException(22105);
        }
        return $ret;
    }

    /**
     * 取消订单
     * @param array $params
     * @return array
     */
    public function cancel(array $params): array
    {
        $id = $params['id'];
        $type = $params['type'];
        $reason = $params['reason'];

        //0-跟团游,1-自由行
        if ($type == 1) {
            $data = [
                'cancel_reason' => $reason,
                'refund_procedure_fee' => 0,
                'order_id' => $id,
            ];
            try {
                $exist = IndependentOrder::find($id);
                if ($exist == false) {
                    throw new Exception('訂單不存在');
                }
                $history = [
                    'operator_id' => 0,
                    'order_id' => $id,
                    'type' => IndependentOrderHistory::TYPE_CANCEL,
                    'params' => json_encode($data)
                ];
                \app\admin\service\OrderService::cancelIndependentOrder($id, $data, 0, 0, 0);
                $history['status'] = IndependentOrderHistory::STATUS_SUCCESS;
                event('OrderHistory', $history);
                event('RequestLog');

            } catch (\Exception $e) {
                $history['status'] = IndependentOrderHistory::STATUS_FAILED;
                event('OrderHistory', $history);
                return ['status' => 0, 'msg' => '取消失败,' . $e->getMessage()];
            }
        } else {
            $data = [
                'reason' => $reason,
                'refund_procedure_fee' => 0,
                'order_id' => $id,
            ];
            $transaction = new Transaction('finance', 'order', 'product');
            $transaction->start();
            try {
                $exist = OrderModel::find($id)->toArray();
                if ($exist == false) {
                    throw new Exception('訂單不存在');
                }
                $data['fee'] = $exist['paid_fee'] ?? 0;
                GroupTour::cancelHandle($data);
                $transaction->commit();
            } catch (\Exception $e) {
                $transaction->rollback();
                return ['status' => 0, 'msg' => $e->getMessage()];
            }
        }
        return ['status' => 1, 'msg' => '取消成功'];
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