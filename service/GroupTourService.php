<?php

namespace app\api\service;

use app\admin\service\OrderService;
use app\admin\service\StockService;
use app\api\lib\BizException;
use app\api\model\app\SearchRecordsModel;
use app\api\model\member\MemberRechargeModel;
use app\api\model\member\MemberSearchModel;
use app\api\model\member\Traveler;
use app\api\model\product\ProductBaseModel;
use comm\constant\CK;
use comm\model\branch\Branch;
use comm\model\insurance\InsurancePlan;
use comm\model\member\MemberManagementModel;
use comm\model\order\CustomerCredentialsModel;
use comm\model\order\OrderCustomerModel;
use comm\model\order\OrderModel;
use comm\model\order\Room;
use comm\model\product\ProductRegister as ProductRegisterModel;
use comm\model\product\ProductReleaseModel;
use comm\model\supplier\SupplierPackageModel;
use comm\model\system\SystemConfig;
use comm\model\SystemAdmin;
use comm\service\EncryptionService as Encryption;
use comm\service\PackageRoomStock;
use think\Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * 跟團遊訂單服務
 * Class GroupOrderService
 * @package app\api\service
 */
class GroupTourService extends ApiServiceBase
{
    //结算结果为正数乘的比例
    public const POSITIVE_INTEREST_RATE = 1.1;

    //结算结果为负数乘的比例
    public const NEGATIVE_INTEREST_RATE = 0.9;
    // room
    private $rooms = [];

    /**
     * 創建訂單
     * @param array $params
     * @param int $member_id
     * @param int $device
     * @return array
     * @throws Exception
     */
    public function create_order(array $params)
    {
        date_default_timezone_set('Asia/Shanghai');
        $time = time();
        Db::startTrans();
        $member_id = $this->member_id;
        $device = $this->device_id;
        $key = CK::APP_CREATE_GROUP_ORDER . $member_id . ':' . $params['product_release_id'];
        $date = date('Y-m-d H:i:s', $time);
        Log::info("APP跟团游下单：member_id:{$member_id},device:{$device},参数:" . json_encode($params, JSON_UNESCAPED_UNICODE) . "時間：$date");
        try {
            $orderModel = new OrderModel();

            //获取订单价格数据
            $res = $this->calculate($params);
            $baseInfo = $res['baseInfo'];
            $member_qty = $res['member_qty'];
            $adult_qty = $res['adult_qty'];
            $child_qty = $res['child_qty'];
            $baby_qty = $res['baby_qty'];
            $total_tour_fee = $res['total_tour_fee'];
            $room_fee = $res['room_fee'];
            $insurance_fee = $res['insurance_fee'];
            $tip_fee = $res['tip_fee'];
            $airport_tax_fee = $res['airport_tax_fee'];
            $stamp_tax_fee = $res['stamp_tax_fee'];
            $membership_fee = $res['membership_fee'];
            $order_fee = $res['order_fee'];
            $travelers = $res['travelers'];
            $travelerDict = $res['travelerDict'];
            $base = $baseInfo->base->toArray();

            //訂單數據
            $staff_id = SystemAdmin::where('account', trim($params['job_num']))->value('id', 0);
            $orderData = [
                'order_sn' => (new OrderNumberService())->getOrderNumber(OrderService::T_TYPE_GROUP),
                'contact_last_name' => $params['contact_last_name'] ?? '',
                'contact_first_name' => $params['contact_first_name'] ?? '',
                'contact_way' => $params['contact_way'] ?? '',
                'guest_notes' => $params['guest_notes'] ?? '',
                'member_qty' => $member_qty,
                'adult_qty' => $adult_qty - $member_qty,
                'child_qty' => $child_qty,
                'baby_qty' => $baby_qty,
                'total_tour_fee' => $total_tour_fee,
                'membership_fee' => $membership_fee,
                'room_fee' => $room_fee,
                'insurance_fee' => $insurance_fee,
                'tip_fee' => $tip_fee,
                'airport_tax_fee' => $airport_tax_fee,
                'stamp_tax_fee' => $stamp_tax_fee,
                'order_fee' => $order_fee,
                'currency_id' => $base['currency'],
                'product_release_id' => $params['product_release_id'],
                'series_id' => $base['series_id'],
                'company_id' => $this->company_id,
//                'company_id' => 10,
                'insurance_company_id' => $base['insur_com_id'],
                'insurance_plan_id' => $base['insur_plan_id'],
                'staff_id' => $staff_id,
                'member_id' => $member_id,
                'branch_id' => Branch::getBranchId($baseInfo->tour_date, $staff_id, (int)$this->company_id),
                'type' => $device,
            ];

            $orderRes = $orderModel->save($orderData);
            if ($orderRes == false) {
                BizException::throwException(32003);
            }
            $order_id = $orderModel->id;

            $sold_qty = $adult_qty + $child_qty + $baby_qty;
            $releaseModel = ProductReleaseModel::find($baseInfo->id);
            $releaseModel->sold_qty += $sold_qty;

            //会员数据
            $rechargeData = [];
            foreach ($travelers as $item) {
                if (isset($travelerDict[$item['id']]) && ($travelerDict[$item['id']]['join_member'] == 1)) {
                    $phone = call_user_func([new Encryption, 'encrypt'], $item['phone']);
                    $member = MemberManagementModel::where(['phone' => $phone, 'company_id' => $this->company_id])->find();
                    $expiry_date = date('Y-m-d', strtotime('+1 year'));
                    if (empty($member)) {
                        $member = new MemberManagementModel();
                        $memberData = [
                            'company_id' => $this->company_id,
                            'member_code' => '',
                            'password' => '123456',
                            'pay_password' => '123456',
                            'title' => $item['gender'],
                            'last_name_cn' => $item['last_name_cn'],
                            'first_name_cn' => $item['first_name_cn'],
                            'last_name_en' => $item['last_name_en'],
                            'first_name_en' => $item['first_name_en'],
                            'birthday' => $item['birthday'],
                            'area_code' => $item['nation_code'] ?? 0,
                            'phone' => $item['phone'] ?? '',
                            'is_member' => 1,
                            'expiry_date' => $expiry_date,
                        ];
                        $member->save($memberData);
                    } else {
                        $member->is_member = 1;
                        $member->expiry_date = $expiry_date;
                        $member->status = 1;
                        $member->save();
                    }

                    $rechargeData[] = [
                        'member_id' => $member->id,
                        'order_id' => $order_id,
                        'staff_id' => 0,
                        'currency_id' => config('system.currency')[2]['id'],
                        'member_fee' => 10,
                    ];
                }
            }

            $rechargeModel = new MemberRechargeModel();
            $rechargeModel->saveAll($rechargeData);

            //添加出行人數據
            foreach ($travelers as $key => $item) {
                $travelers[$key]['credentials'] = array_column($item['credentials'], NULL, 'credentials_type');
                $orderCustomerData = [
                    'order_id' => $order_id,
                    'phone' => $item['phone'],
                    'birthday' => $item['birthday'],
                    'chinese_last_name' => $item['last_name_cn'],
                    'chinese_first_name' => $item['first_name_cn'],
                    'english_last_name' => $item['last_name_en'],
                    'english_first_name' => $item['first_name_en'],
                    'title' => $item['gender'] == 0 ? 0 : 2,
                    'location' => $item['nation_code'] == 86 ? 'cn' : ($item['nation_code'] == 852 ? 'hk' : ''),
                    'credential_type' => $travelerDict[$item['id']]['credentials_type'] ?? 0,
                    'is_insurance' => in_array($item['id'], $params['insurances'] ?? []) ? 1 : 0,
                    'insurance_file' => '',
                    'emergency_name' => $params['contact_last_name'] . $params['contact_first_name'],
                    'emergency_phone' => $params['contact_way'],
                    'travel_start_location_meeting' => $travelerDict[$item['id']]['location_id'] ?? 0,
                    'travel_start_location_name' => $travelerDict[$item['id']]['location_time'] ?? NULL,
                    'type' => $item['type'],
                    'create_time' => $time,
                    'update_time' => $time,
                    'is_member' => (isset($travelerDict[$item['id']]) && ($travelerDict[$item['id']]['join_member'] == 1)) ? 1 : 0,
                ];
                $orderCustomerModel = new OrderCustomerModel();
                $orderCustomerModel->save($orderCustomerData);
                foreach ($params['travelers'] as $v) {
                    foreach ($item['credentials'] as $credential) {
                        if ($credential['credentials_type'] == $v['credentials_type']) {
                            $credentialsModel = new CustomerCredentialsModel();
                            $credentialsModel->save([
                                'credentials' => $credential['credentials_type'],
                                'credentials_num' => $credential['credentials_number'],
                                'credentials_vaild' => $credential['expire_date'],
                                'order_customer_id' => $orderCustomerModel->id,
                            ]);
                        }
                    }
                }
            }

            //添加房間數據
            $roomData = [];
            if ($params['room']) {
                foreach ($params['room'] as $key => $item) {
                    $roomData[] = [
                        'order_id' => $order_id,
                        'days' => $item['group_number'],
                        'package_id' => $item['is_upgrade_room'] ? $item['after_package_id'] : $item['package_id'],
                        'occupied' => $item['num_room'],
                        'extra' => $item['extra_bed_num'],
                        'remark' => $item['remark'],
                    ];
                }
            }
            $roomModel = new Room();
            $roomModel->saveAll($roomData);

            //返回数据
            $releaseField = 'id,product_base_id,tour_status,car_no,tour_date,tour_finish_date,member_fee,adult_fee,child_fee,baby_fee,';
            $releaseField .= 'guide_remark,internal_remark';
            $orderField = 'id,order_sn,status,adult_qty,child_qty,baby_qty,total_tour_fee,room_fee,insurance_fee,addition_item_fee,tip_fee,';
            $orderField .= 'airport_tax_fee,stamp_tax_fee,order_fee,currency_id,product_release_id,insurance_plan_id';
            $res = OrderModel::with([
                'release' => function ($query) use ($releaseField) {
                    $query->field($releaseField)->with(['base' => function ($query) {
                        $query->field('product_id,pid,product_name,product_code,total_days');
                    }]);
                },
                'customer' => function ($query) {
                    $query->field('id,order_id,phone,birthday,chinese_last_name,chinese_first_name,english_last_name,english_first_name');
                }
            ])
                ->field($orderField)
                ->find($order_id)->toArray();
            $res['status_desc'] = OrderModel::STATUS[$res['status']] ?? '';
            $res['insurance_name'] = InsurancePlan::where('id', $res['insurance_plan_id'])->value('name', '');
            $res['currency'] = $res['currency_id'] == 1 ? '¥' : '$';
            Db::commit();
        } catch (\Exception $exception) {
            Db::rollback();
//            dd($exception->getFile() . $exception->getLine() . $exception->getMessage());
            BizException::throwException(9000, $exception->getMessage());
        }
        $this->useRoom();
        return $res;
    }

    public function calculate($params, $calc = false)
    {
        //獲取團數據
        $baseInfo = $this->getBaseInfo($params);
        if (strtotime($baseInfo->tour_date) < strtotime('Y-m-d 00:00:00', strtotime('+1 day'))) {
            BizException::throwException(32004);
        }
        $tour_status = CommonService::group_status($baseInfo->sold_qty, $baseInfo->base->customer_num, $baseInfo->base->customer_min);
        if (in_array($tour_status, [0, 4])) {
            BizException::throwException(32019);
        }

        //獲取出行人信息
        $adult_qty = $params['adult_qty'] ?? 0;
        $child_qty = $params['child_qty'] ?? 0;
        $baby_qty = $params['baby_qty'] ?? 0;
        $sold_qty = $adult_qty + $child_qty + $baby_qty;
        if ($sold_qty != count($params['travelers'])) {
            BizException::throwException(32005);
        }
        if ($baseInfo->sold_qty + $sold_qty > $baseInfo->base->customer_num) {
//            $diff_qty = $baseInfo->base->customer_num - $baseInfo->sold_qty;
//            BizException::throwException(32012, [$diff_qty]);
            BizException::throwException(32012);
        }
        $travelerDict = array_column($params['travelers'], NULL, 'id');
        $travelers = Traveler::where('id', 'in', array_keys($travelerDict))->with('credentials')->select()->toArray();
        if ($travelers == false) {
            BizException::throwException(32006);
        }

        $total_tour_fee = 0;//團費
        $member_fee = 0;//升级会员费用
        $member_qty = 0;
        $config = config('system.credentials');
        foreach ($travelers as $item) {
            $types = array_column($item['credentials'], 'credentials_type');
            $area_codes = array_column(config('system.area_code'), 'code');
            if (in_array($item['nation_code'], $area_codes)) {
                $name = $item['last_name_cn'] . $item['first_name_cn'];
            } else {
                $name = $item['last_name_en'] . ' ' . $item['first_name_en'];
            }
            if (isset($travelerDict[$item['id']])) {
                $type = $travelerDict[$item['id']]['credentials_type'];
                if (!in_array($type, $baseInfo->base->credentials)) {
                    if ($baseInfo->base->credentials) {
                        $credentials = [];
                        foreach ($baseInfo->base->credentials as $v) {
                            $credentials[] = $config[$v]['name'] ?? '';
                        }
                        BizException::throwException(32015, [count($credentials) == 1 ? $credentials[0] : (implode('/', $credentials) . '其一')]);
                    }
                }
                if (!in_array($type, $types)) {
                    BizException::throwException(32022, [$name, $config[$type]['name'] ?? '']);
                }
            }
            $member = MemberManagementModel::getMemberStatus($item['phone'], $this->company_id);
            if (isset($member['status']) && $member['status'] == 0) {
                BizException::throwException(32024, [$name, $config[$type]['name'] ?? '']);
            }
            if (!empty($travelerDict[$item['id']]['join_member'])) {
                //会员未到期
                if ($member['member_status'] == 'registered' && ($travelerDict[$item['id']]['join_member'] == 1)) {
                    BizException::throwException(32013);
                } else if ($travelerDict[$item['id']]['join_member'] == 1) {
                    $member_fee += 10;
                }
            }
            if ($travelerDict[$item['id']]['join_member'] == 1 || $member['member_status'] == 'registered') {
                $member_qty += 1;
            }
            switch ($item['type']) {
                case 'adult':
                    $total_tour_fee = ceil(bcadd($total_tour_fee, ($travelerDict[$item['id']]['join_member'] == 1 || $member['member_status'] == 'registered') ? $baseInfo->member_fee : $baseInfo->adult_fee));
                    break;
                case 'child':
                    $total_tour_fee = ceil(bcadd($total_tour_fee, $baseInfo->child_fee));
                    break;
                case 'baby':
                    $total_tour_fee = ceil(bcadd($total_tour_fee, $baseInfo->baby_fee));
                    break;
            }
        }
        $base = $baseInfo->base;

        //房費相關
        $room_fee = 0;
        if (!empty($params['room'])) {
            $room_fee = $this->get_room_fee($params, $baseInfo, $adult_qty, true);
        }

        // 如果小费是报名时预缴 就需要收小费
        $tip_fee = 0;
        if ((int)$base->tip_type == 1) {
            $tip_fee = ceil(bcmul($sold_qty, self::tip_fee($base->currency, $base->tip_currency, $base->tip_money, $base->tip_days)));
        }

        // 保险费用
        $premium = InsurancePlan::premium($base->total_days, $base->insur_plan->toArray());
        if (fmod((float)$premium, 1) <= 0) {
            $premium = ceil($premium);
        }
        $insurance_fee = ceil(bcmul($premium, count($params['insurances'] ?? [])));
        $base = $base->toArray();

        //機場稅
        $airport_tax = [
            'adult' => 0,
            'child' => 0,
            'baby' => 0,
        ];
        if (count($base['traffic']) > 0) {
            foreach ($base['traffic'] as $item) {
                if ((int)$item['traffic_type'] === 0) {
                    $rate = (string)self::getExchangeRate($item['fee_default_currency'], $base['currency']);
                    $airport_tax['adult'] = ceil((float)bcadd((string)$airport_tax['adult'], bcmul((string)$item['fee_adult'], $rate)));
                    $airport_tax['child'] = ceil((float)bcadd((string)$airport_tax['child'], bcmul((string)$item['fee_child'], $rate)));
                    $airport_tax['baby'] = ceil((float)bcadd((string)$airport_tax['baby'], bcmul((string)$item['fee_baby'], $rate)));
                }
            }
        }
        $airport_tax_fee = ceil(bcadd(bcadd(bcmul($adult_qty, $airport_tax['adult']), bcmul($child_qty, $airport_tax['child'])), bcmul($baby_qty, $airport_tax['baby'])));
        $order_fee = (float)$member_fee + (float)$total_tour_fee + (float)$room_fee + (float)$insurance_fee + (float)$tip_fee + (float)$airport_tax_fee;

        //印花稅,訂單總價 * 0.0015 舍去法取整
        //前提，香港公司产品
        //1、香港出发，目的地是香港 不要
        //2、香港出发，目的地非香港，要
        //3、非香港出发，行程天数大于1天 要
        //4、非香港出发，行程天数1天 不要
        $stamp_tax_fee = 0;
        if (($base['group_region'] == ProductBaseModel::GROUP_REGION_HK) && (
                ((strpos($base['from_city'], '10010033') !== false) && (strpos($base['to_city'], '10010033') === false)) ||
                ((strpos($base['from_city'], '10010033') === false) && ($base['total_days'] > 1)))
        ) {
            $stamp_tax_fee = floor(bcmul($order_fee, 0.0015));
            $order_fee = ceil(bcadd($order_fee, $stamp_tax_fee));
        }

        $data = [
            'total_tour_fee' => $total_tour_fee,
            'room_fee' => $room_fee,
            'tip_fee' => $tip_fee,
            'insurance_fee' => $insurance_fee,
            'airport_tax_fee' => $airport_tax_fee,
            'stamp_tax_fee' => $stamp_tax_fee,
            'order_fee' => $order_fee,
            'membership_fee' => $member_fee,
            'symbol' => $base['currency'] == 1 ? '¥' : '$',
        ];
        if ($calc == false) {
            $data['baseInfo'] = $baseInfo;
            $data['member_qty'] = $member_qty;
            $data['adult_qty'] = $adult_qty;
            $data['child_qty'] = $child_qty;
            $data['baby_qty'] = $baby_qty;
            $data['travelers'] = $travelers;
            $data['travelerDict'] = $travelerDict;
        }
        return $data;
    }

    /**
     * 获取小费
     * @param int $base_currency
     * @param int $tip_currency
     * @param float $tip_money
     * @param int $tip_days
     * @return false|float
     */
    public static function tip_fee(int $base_currency, int $tip_currency, float $tip_money, int $tip_days = 1)
    {
        return ceil((float)bcmul(bcmul((string)$tip_money, (string)$tip_days), (string)self::getExchangeRate($tip_currency, $base_currency)));
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

    /**
     * 获取利率
     *
     * @param $fee
     * @return float
     */
    public static function getInterestRate($fee): float
    {
        return $fee > 0 ? self::POSITIVE_INTEREST_RATE : self::NEGATIVE_INTEREST_RATE;
    }

    /**
     * 不佔床费用 = ((套餐价 / 可使用人数) * -1  + 套餐项目价格(除房间外的项目) * 汇率 * 利率
     *
     * @param int|float $fee 套餐价格
     * @param int $use_qty 使用人数
     * @param int|float $etceteras_fee 附加项目费用
     * @param int|float $exchange_rate 汇率
     */
    public static function no_bed_fee($fee, int $use_qty, $etceteras_fee, $exchange_rate)
    {
        $no_bed_fee = bcadd(bcmul(bcdiv((string)$fee, (string)$use_qty), (string)-1), (string)$etceteras_fee);
        return ceil((float)bcmul(bcmul($no_bed_fee, (string)$exchange_rate), (string)self::getInterestRate($no_bed_fee)));
    }

    /**
     * 搜索前置数据
     * @param $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function search($params)
    {
        $where = [
            'member_id' => $this->member_id,
            'company_id' => $this->company_id,
            'type' => 1,
        ];
        $history = MemberSearchModel::where($where)
            ->field('words')
            ->order('create_time', 'desc')
            ->limit(10)->select()->toArray();
        unset($where['member_id']);
        $hot = SearchRecordsModel::where($where)
            ->field('words,words_pinyin,times')
            ->order('times', 'desc')
            ->limit(10)->select()->toArray();
        return compact('history', 'hot');
    }

    /**
     * 旅行团座位信息
     * @param array $params
     * @return array
     * @throws \JsonException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function seat_list(array $params)
    {
        $product_release_id = $params['product_release_id'];
        $baseInfo = ProductReleaseModel::with([
            'base' => function ($query) {
                $query->with([
                    'traffic' => function ($query) {
                        $query->with(['car.position'])
                            ->where('traffic_type', 5)
                            ->field('id,product_id,traffic_type,start_day,supplier,route,fee_type,car_type,car_model');
                    }
                ])->field('product_id,pid,product_name,product_code');
            }
        ])
            ->field('id,product_base_id,car_no')
            ->where('id', $product_release_id)
            ->findOrFail()
            ->toArray();
        $position = $baseInfo['base']['traffic'][0]['car']['position'] ?? [];

        //保留座位占座
        $reserved = $this->get_reserved_seat($baseInfo['base']['product_id']);

        //下单占座
        $assigned = $this->get_assigned_seat($product_release_id);
        return compact('position', 'reserved', 'assigned');
    }

    /**
     * 选座
     * @param array $params
     * @return mixed
     * @throws Exception
     * @throws \JsonException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function select_seat(array $params)
    {
        try {
            $product_release_id = $params['product_release_id'];
            $seats = $params['seats'];
            $baseInfo = ProductReleaseModel::with(['base' => function ($query) {
                $query->field('product_id,pid');
            }])->findOrFail($product_release_id);

            if (array_column($seats, 'id') != array_unique(array_column($seats, 'id'))) {
                throw new Exception("出行人數據重複");
            }
            if (array_column($seats, 'seat_no') != array_unique(array_column($seats, 'seat_no'))) {
                throw new Exception("座位數據重複");
            }
            $order = OrderModel::field('id,status')->findOrFail($params['order_id']);
            if ($order->status > 3) {
                throw new Exception("訂單已取消");
            }
            $reserved = $this->get_reserved_seat($baseInfo['base']['product_id']);
            $assigned = $this->get_assigned_seat($product_release_id);
            foreach ($seats as $item) {
                if (in_array($item['seat_no'], $reserved) || in_array($item['seat_no'], $assigned)) {
                    throw new Exception("座位号为 {$item['seat_no']} 的座位已被佔用,请更換其它座位");
                }
                $field = 'id,chinese_last_name,chinese_first_name,english_last_name,english_first_name,seat_no';
                $model = OrderCustomerModel::field($field)->where(['id' => $item['id'], 'order_id' => $params['order_id']])->findOrEmpty();
                if ($model->isEmpty()) {
                    throw new Exception("ID為{$item['id']}的出行人數據不存在");
                }
                $cn = $model->chinese_last_name . $model->chinese_first_name;
                $en = $model->english_first_name . $model->english_last_name;
                $name = $cn ?: $en;
                if (!empty($model->seat_no)) {
                    throw new Exception("出行人 $name 已經佔座,無需重複選座");
                }
                $model->save(['seat_no' => $item['seat_no']]);
            }

            // 暂存已安排数据
            ProductRegisterModel::cachingAssigned(CK::ASSIGNED_CAR_SEAT . $product_release_id, array_column($seats, 'seat_no'));
            return ['status' => 1];
        } catch (\Exception $exception) {
            Db::rollback();
            throw new Exception($exception->getMessage());
        }
    }

    /**
     * 保留座位占座
     * @param $product_id
     * @return array|mixed
     * @throws \JsonException
     */
    public function get_reserved_seat($product_id)
    {
        $reserved = ProductRegisterModel::reservation($product_id, 0);
        return $reserved['reserve_seat'] ?? [];
    }

    /**
     * 下单占座
     * @param $product_release_id
     * @return array
     * @throws \JsonException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function get_assigned_seat($product_release_id)
    {
        $assigned_info = ProductRegisterModel::assigned($product_release_id);
        return array_keys($assigned_info);
    }

    /**
     * @param $params
     * @param $baseInfo
     * @param $adult_qty
     * @param false $reduce
     * @return false|float
     */
    private function get_room_fee($params, $baseInfo, $adult_qty, $reduce = false)
    {
        if (count($params['room']) > 1 && array_sum(array_column($params['room'], 'num_room')) == 0) {
            BizException::throwException(32026);
        }
        $room_fee = 0;
        $room = array_column($params['room'], null, 'group_number');
        $stock_info = CommonService::getUpgradePackage($baseInfo->base, $baseInfo->tour_date, $baseInfo->base->currency);
        $stock_total_num = array_sum(array_column($stock_info['upgradeable_package'], 'room_qty'));
        $sys_days = array_column($stock_info['main_package'] ?? [], 'group_number');
        $user_days = array_keys($room);
        $ex = array_diff($sys_days, $user_days);
        if ($ex != []) {
            BizException::throwException(32027, [implode(',', $ex)]);
        }
        if ($stock_info['main_package']) {
            foreach ($stock_info['main_package'] as $item) {
                foreach ($room as $key => $v) {
                    if (in_array($v['group_number'], $item['days'])) {
                        //基礎套餐
                        if ($v['is_upgrade_room'] == 0) {
                            if ($v['num_room'] == 0) {
                                BizException::throwException(32027, [$v['group_number']]);
                            }
                            $add_day = $v['group_number'] - 1;
                            $date = date('Y-m-d', strtotime("+$add_day day", strtotime($baseInfo->tour_date)));
                            $min_room_qty = ceil($adult_qty / $item['checkin_qty']);
                            $max_room_qty = $adult_qty;

                            //庫存計算
                            $room_qty = PackageRoomStock::get($date, $v['package_id'], StockService::FIELD_QTY_GROP);
                            if ($room_qty <= 0) {
                                if ($stock_total_num > 0) {
                                    BizException::throwException(32018, [$v['group_number']]);
                                } else {
                                    BizException::throwException(32017, [$v['group_number']]);
                                }
                            }
                            if ($v['num_room'] > $room_qty) {
                                BizException::throwException(32016, [$room_qty]);
                            }

                            //最大房間數
                            if ($v['num_room'] > $max_room_qty) {
                                BizException::throwException(32014, [$v['group_number'], $max_room_qty]);
                            }
                            if ($v['num_room'] == $max_room_qty) {
                                $room_fee += ($item['diff_fee'] * (($item['checkin_qty'] * $v['num_room']) - $adult_qty));
                                if ($reduce === true) {
                                    $this->storeRoom($date, $v['package_id'], $v['num_room']);
                                }
                                continue;
                            }

                            //補房差數+不佔床數
                            $ct = 0;

                            //加床
                            if ($v['is_extra_bed'] == 1) {
                                if ($item['is_extra_bed'] == false) {
                                    BizException::throwException(32008, [$v['group_number']]);
                                }
                                if ($v['extra_bed_num'] > 1) {
                                    BizException::throwException(32025, [$v['group_number']]);
                                }
                                $ct += 1;
                                $room_fee += ($item['extra_price'] * $v['extra_bed_num']);
                                $min_room_qty = ceil(($adult_qty - 1) / $item['checkin_qty']);
                            }

                            //不占床
                            if ($v['num_room'] < $min_room_qty) {
                                if ($item['is_occupy_bed'] == false) {
                                    BizException::throwException(32007, [$v['group_number'], $min_room_qty]);
                                }
                                $ct += 1;
                                $min_room_qty = ceil(($adult_qty - ($v['is_extra_bed'] == 1 ? 2 : 1)) / $item['checkin_qty']);
                                $room_fee += $item['no_bed_fee'];
                            }
                            if ($v['num_room'] < $min_room_qty) {
                                BizException::throwException(32007, [$v['group_number'], $min_room_qty]);
                            }

                            //補房差
//                            $room_fee += ($item['diff_fee'] * (($item['checkin_qty'] * $v['num_room']) - ($adult_qty - $ct)));
                            Log::write('is_upgrade_room= 0,checkin_qty:' . $item['checkin_qty'] . 'num_room:' . $v['num_room'] . 'adult_qty:' . $adult_qty);
                            $diffNum = (int)bcsub((string)bcmul((string)$item['checkin_qty'], (string)$v['num_room'], 0), $adult_qty, 0);
                            if ($diffNum >= 0) {
                                $room_fee += (int)bcmul((string)$item['diff_fee'], (string)$diffNum, 0);
                            }

                            $room_qty = PackageRoomStock::get($date, $v['package_id'], StockService::FIELD_QTY_GROP);
                            if ($room_qty <= 0) {
                                if ($stock_total_num > 0) {
                                    BizException::throwException(32018, [$v['group_number']]);
                                } else {
                                    BizException::throwException(32017, [$v['group_number']]);
                                }
                            }
                            if ($v['num_room'] > $room_qty) {
                                BizException::throwException(32016, [$room_qty]);
                            }
                            //扣除庫存
                            if ($reduce === true) {
                                $this->storeRoom($date, $v['package_id'], $v['num_room']);
                            }
                        }
                    }
                }
            }
        }

        //要升級房型但是無可升級套餐
        if (count(array_unique(array_column($room, 'is_upgrade_room'))) > 0 && empty($stock_info['upgradeable_package'])) {
            BizException::throwException(32021);
        } else {
            //升級房型
            foreach ($stock_info['upgradeable_package'] as $item) {
                foreach ($room as $key => $v) {
                    if ($v['after_package_id'] == $item['package_id'] && $item['start_day'] == $v['group_number']) {
                        if ($v['is_upgrade_room'] == 1) {
                            if ($v['num_room'] == 0) {
                                BizException::throwException(32027, [$v['group_number']]);
                            }
                            $add_day = $v['group_number'] - 1;
                            $date = date('Y-m-d', strtotime("+$add_day day", strtotime($baseInfo->tour_date)));
                            $min_room_qty = ceil($adult_qty / $item['checkin_qty']);
                            $max_room_qty = $adult_qty;

                            //最大房間數
                            if ($v['num_room'] > $max_room_qty) {
                                BizException::throwException(32014, [$v['group_number'], $max_room_qty]);
                            }

                            //升級房套餐沒有庫存
                            $room_qty = PackageRoomStock::get($date, $v['after_package_id'], StockService::FIELD_QTY_GROP);
                            if ($room_qty <= 0) {
                                $tmp_upg_room_qty = 0;
                                foreach ($stock_info['upgradeable_package'] as $upg) {
                                    //獲取當天其他升級套餐庫存
                                    if (($v['group_number'] == $upg['start_day']) && ($v['after_package_id'] != $upg['package_id'])) {
                                        $tmp_upg_room_qty = PackageRoomStock::get($date, $upg['package_id'], StockService::FIELD_QTY_GROP);
                                        if ($tmp_upg_room_qty > 0) {
                                            BizException::throwException(32018, [$v['group_number']]);
                                        }
                                    }
                                }
                                //當天其他升級套餐也沒庫存
                                if ($tmp_upg_room_qty <= 0) {
                                    $tmp_main_room_qty = 0;
                                    foreach ($stock_info['main_package'] as $main) {
                                        //獲取當天其他基礎套餐庫存
                                        if ((in_array($v['group_number'], $main['days'])) && ($v['after_package_id'] != $main['package_id'])) {
                                            $tmp_main_room_qty = PackageRoomStock::get($date, $main['package_id'], StockService::FIELD_QTY_GROP);
                                            if ($tmp_main_room_qty <= 0) {
                                                continue;
                                            }
                                        }
                                    }
                                    //當天其他原始套餐和升級套餐都沒庫存
                                    if ($tmp_main_room_qty <= 0) {
                                        BizException::throwException(32020, [$v['group_number']]);
                                    } else {
                                        BizException::throwException(32021, [$v['group_number']]);
                                    }
                                }
                            }
//                            if ($v['num_room'] == $max_room_qty) {
//                                $room_fee += ($item['diff_fee'] * (($item['checkin_qty'] * $v['num_room']) - $adult_qty));
//                                if ($reduce === true) {
//                                    $this->storeRoom($date, $v['after_package_id'], $v['num_room']);
//                                }
////                                continue;
//                            }

                            //補房差數+不佔床數
                            $ct = 0;
                            //加床
                            if ($v['is_extra_bed'] == 1) {
                                if ($item['is_extra_bed'] == false) {
                                    BizException::throwException(32008, [$v['group_number']]);
                                }
                                if ($v['extra_bed_num'] > 1) {
                                    BizException::throwException(32025, [$v['group_number']]);
                                }
                                $ct += 1;
                                $room_fee += ($item['extra_price'] * $v['extra_bed_num']);
                                $min_room_qty = ceil(($adult_qty - 1) / $item['checkin_qty']);
                            }
                            //不占床
                            if ($v['num_room'] < $min_room_qty) {
                                if ($item['is_occupy_bed'] == false) {
                                    BizException::throwException(32007, [$v['group_number'], $min_room_qty]);
                                }
                                $ct += 1;
                                $min_room_qty = ceil(($adult_qty - ($v['is_extra_bed'] == 1 ? 2 : 1)) / $item['checkin_qty']);
                                $room_fee += $item['no_bed_fee'];
                            }
                            if ($v['num_room'] < $min_room_qty) {
                                BizException::throwException(32007, [$v['group_number'], $min_room_qty]);
                            }
                            //補房差
//                            $room_fee += ($item['diff_fee'] * (($item['checkin_qty'] * $v['num_room']) - ($adult_qty - $ct)));
                            Log::write('is_upgrade_room=1,checkin_qty:' . $item['checkin_qty'] . 'num_room:' . $v['num_room'] . 'adult_qty:' . $adult_qty);
                            $diffNum = (int)bcsub((string)bcmul((string)$item['checkin_qty'], (string)$v['num_room'], 0), $adult_qty, 0);
                            if ($diffNum >= 0) {
                                $room_fee += (int)bcmul((string)$item['diff_fee'], (string)$diffNum, 0);
                            }
                            Log::write('升级房费:' . $item['upgradeable_fee'] * $v['num_room']);
                            $room_fee += $item['upgradeable_fee'] * $v['num_room'];
                            if ($reduce === true) {
                                $this->storeRoom($date, $v['after_package_id'], $v['num_room']);
                            }
                        }
                    }
                }
            }
        }
        return ceil($room_fee);
    }

    private function storeRoom($date, $packId, $qty)
    {
        array_push($this->rooms, ['date' => $date, 'pack' => $packId, 'qty' => $qty]);
    }

    private function useRoom()
    {
        if (!empty($this->rooms)) {
            foreach ($this->rooms as $room) {
                PackageRoomStock::reduce($room['date'], $room['pack'], StockService::FIELD_QTY_GROP, $room['qty']);
            }
        }
    }

    public function getRoomFee(array $params)
    {
        Log::write('跟團遊下單,獲取房費:' . json_encode($params, JSON_UNESCAPED_UNICODE));
        return $this->get_room_fee($params, $this->getBaseInfo($params), $params['adult_qty']);
    }

    private function getBaseInfo(array $params)
    {
        return ProductReleaseModel::with(['base' => function ($query) {
            $query->with([
                'insur_plan',
                'traffic' => static fn($query) => $query->with(['car.position'])->whereIn('traffic_type', [0, 5]),
                'schedule' => static fn($query) => $query->with([
                    'supplier.package.item', 'room', 'package.item'
                ])->where('type', 'hotel')])
                ->field('product_id,pid,series_id,theme_id,customer_num,customer_min,currency,insur_com_id,insur_plan_id,tip_type,tip_money,tip_currency,tip_days,ad_cost,other_cost,total_days,group_region,from_city,to_city,credentials');
        }])
            ->field('id,product_base_id,tour_status,car_no,tour_date,tour_finish_date,member_fee,adult_fee,child_fee,baby_fee,sold_qty')
            ->where('id', $params['product_release_id'])
            ->findOrFail();
    }
}