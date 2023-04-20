<?php
/**
 * Description : 自由行订单服务
 * Author      : Kobin
 * CreateTime  : 2021/8/12 下午4:06
 */

namespace app\api\service;


use app\admin\service\OrderService;
use app\admin\service\PriceService;
use app\admin\service\StockService;
use app\api\lib\BizException;
use app\api\model\independent\IndependentCombination;
use app\api\model\member\MemberModel;
use app\api\model\order\MemberCartModel;
use comm\constant\CN;
use comm\model\branch\Branch;
use comm\model\independent\IndependentOrder;
use comm\model\independent\IndependentOrderCustomer;
use comm\model\independent\IndependentOrderDetail;
use comm\model\independent\IndependentOrderDiscount;
use comm\model\independent\IndependentOrderItems;
use comm\model\independent\IndependentOrderPlan;
use comm\model\insurance\InsurancePlan;
use comm\model\supplier\SupplierAirlineRouteModel;
use comm\model\supplier\SupplierCarRouteFee;
use comm\model\supplier\SupplierMenuModel;
use comm\model\supplier\SupplierPackageModel;
use comm\model\supplier\SupplierScenicSpotItemModel;
use comm\model\supplier\SupplierShipRouteModel;
use comm\model\supplier\SupplierSpecialDayFee;
use comm\model\system\SystemConfig;
use think\exception\ErrorException;
use think\facade\Log;

class IndependentOrderService extends ApiServiceBase
{
    const TYPE_MODEL = [
        CN::TYPE_HOTEL => SupplierPackageModel::class,
        CN::TYPE_RESTAURANT => SupplierMenuModel::class,
        CN::TYPE_SCENE => SupplierScenicSpotItemModel::class,
        CN::TYPE_CAR => SupplierCarRouteFee::class,
        CN::TYPE_SHIP => SupplierShipRouteModel::class,
        CN::TYPE_AIR => SupplierAirlineRouteModel::class,
        CN::TYPE_COMBINATION => IndependentCombination::class,
        CN::TYPE_INSURANCE => InsurancePlan::class
    ];

    // 00000000
    const TYPE_TO_CONTENT = [
        CN::TYPE_HOTEL => 1,  //00000001
        CN::TYPE_SCENE => 2,  //00000010
        CN::TYPE_CAR => 4,  //00000100
        CN::TYPE_SHIP => 8,  //00001000
        CN::TYPE_AIR => 16, //00010000
        CN::TYPE_COMBINATION => 32, //00100000
        CN::TYPE_INSURANCE => 64, //01000000
    ];

    const FIELDS_TITLE = 'title';
    const FIELDS_NAME_CN = 'name_cn';
    const FIELDS_NAME_EN = 'name_en';
    const FIELDS_CONTACT = 'contact';
    const FIELDS_IDENTITY = 'identity';
    const FIELDS_BIRTHDAY = 'birthday';

    const FIELDS = [
        self::FIELDS_TITLE => [
            'field' => 'title',
            'field_name' => '稱呼',
            'detail' => [
                'title' => '稱呼',
            ]
        ],
        self::FIELDS_NAME_CN => [
            'field' => 'name_cn',
            'field_name' => '中文姓名',
            'detail' => [
                'firstName_cn' => '名',
                'lastName_cn' => '姓',
            ]
        ],
        self::FIELDS_NAME_EN => [
            'field' => 'name_en',
            'field_name' => '英文姓名',
            'detail' => [
                'firstName_en' => '名',
                'lastName_en' => '姓',
            ]
        ],
        self::FIELDS_CONTACT => [
            'field' => 'contact',
            'field_name' => '電話號碼',
            'detail' => [
                'area_code' => '區號',
                'phone' => '號碼',
            ]
        ],
        self::FIELDS_IDENTITY => [
            'field' => 'identity',
            'field_name' => '證件',
            'detail' => [
                'credentials_type' => '證件類型',
                'credentials_number' => '證件號碼',
                'valid' => '證件有效期',
            ]
        ],
        self::FIELDS_BIRTHDAY => [
            'field' => 'birthday',
            'field_name' => '生日',
            'detail' => [
                'birthday' => '生日',
            ]
        ],
    ];

    // 來源
    private int $origin = 2;
    private bool $is_cal = false;
    private array $fields = [];

    private int $orderAmount = 0;
    private array $exchange_rate;
    private int $currency;
    private array $rooms = [];
    private int $orderDiscount = 0;

    // itemDetail
    private array $itemDetail = [];
    // 请求数据
    private array $params = [];
    // 订单ID
    private int $order_id = 0;
    // type_content
    private int $type_content = 0;
    // 订单编号
    private string $orderNum = '';
    // order
    private array $orderInfo = [];
    // order details
    private array $orderDetails = [];
    // 产品
    private array $products = [];
    // 旅客
    private array $customer = [];
    private array $contact = [];
    // discountItems
    private array $discount_detail = [];
    // itemDetails
    private array $itemDetails = [];
    // dateList
    private array $dateList = [];
    /**
     * @var PriceService
     */
    private PriceService $priceService;
    /**
     * @var StockService|array
     */
    private $stockService;
    private array $dateInArray = [];
    private array $dateOutArray = [];

    public function __construct(int $company_id, int $member_id, $device_id, $params)
    {
        parent::__construct($company_id, $member_id, (int)$device_id);
        $this->exchange_rate = SystemConfig::getExchangeRate('hk_currency');
        $this->priceService = PriceService::getInstance();
        $this->stockService = StockService::getInstance();
        $this->params = $params;
    }


    /**
     * @return array
     * @throws \ErrorException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \Exception
     */
    public function orderCreate(): array
    {
        // 订单号
        $this->orderNum = (new OrderNumberService())->getOrderNumber(OrderService::T_TYPE_INDEPENDENT);
        $model = new IndependentOrder();
        $model->startTrans();
        try {
            $this->is_cal = false;
            $this->getProduct()
                ->handleCartContent()
                ->handleOrder()
                ->handleCustomer();
            $this->savePlan();
            $this->saveItemDetails();
            $this->saveDiscount();
            $this->saveOrderDetail();
            $model->commit();
        } catch (\Exception $exception) {
            $model->rollback();
            throw new \Exception($exception->getFile() . $exception->getLine() . $exception->getMessage());
        }
        $this->useRoomQty();
        return [
            'tips_time' => env('config.ORDER_AUTO_CANCEL_MINUTE') * 60,
            'order_id' => $this->order_id,
            'order_num' => $this->orderNum,
            'currency' => $this->currency,
            'after_discount' => (float)bcdiv((string)bcsub((string)$this->orderAmount, (string)$this->orderDiscount), (string)100),
        ];

    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getCalculateResult(): array
    {
        try {
            $currency = config('system.currency');
            $this->calculate();
            return [
                'currency' => $this->currency,
                'symbol' => $currency[$this->currency]['symbol'],
                'orderAmount' => (float)bcdiv((string)$this->orderAmount, (string)100),
                'orderDiscount' => (float)bcdiv((string)$this->orderDiscount, (string)100),
                'after_discount' => (float)bcdiv((string)bcsub((string)$this->orderAmount, (string)$this->orderDiscount), (string)100),
                'items' => $this->itemDetail,
                'discount' => $this->discount_detail,
                'fields' => $this->fields
            ];
        } catch (\Exception $e) {
            log::error('自由行订单预览错误' . $e->getFile() . $e->getLine() . $e->getMessage());
            BizException::throwException(9000, $e->getMessage());

        }
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function addProduct(): array
    {
        $ret = $this->getProduct()
            ->AddToMemberCart();
        return ['data' => $ret];
    }


    /**
     * @param $params
     * @return $this
     * @throws \Exception
     */
    public function calculate()
    {
        $this->is_cal = true;
        try {
            $this->getProduct()->handleCartContent();
        } catch (\Exception $exception) {
            throw new \Exception($exception->getFile() . $exception->getLine() . $exception->getMessage());
        }
        return $this;
    }

    /**
     * @return $this
     */
    public function handleCartContent(): IndependentOrderService
    {
        $currency = config('system.currency');
        $types_array = [];
        foreach ($this->params['product'] as $pid => $item) {
            // fields
            if ($this->is_cal && !empty($this->products[$pid]['fields'])) {
                foreach ($this->products[$pid]['fields'] as $f) {
                    foreach (CN::FIELDS[$f['field']]['detail'] as $k => $i) {
                        array_push($this->fields, $k);
                    }
                }
            }
            // 单个优惠组合的价格和总额
            $price = 0;
            $amount = 0;
            $items = [];
            foreach ($item['items'] as $innerItem) {
                //types
                array_push($types_array, $innerItem['type']);
//                $info = self::getTypeItemDetail($innerItem['type'], $innerItem['item_id']);
                $snap = $this->products[$pid]['detail'][$innerItem['detail_id']]['snapshot'];
                $snap = json_decode($snap, true);
                if ($innerItem['type'] == 1) {
                    if (isset($snap[0])) {
                        $snap = array_column($snap, null, 'id');
                        $info = $snap[$innerItem['item_id']];
                    } else {
                        $info = $snap;
                    }
                } else {
                    $info = $snap;
                }

                $day = isset($info['days']) ? $info['days'] - 1 : 0;
                $in = strtotime($innerItem['start_date']);
                $out = strtotime('+' . $day . ' days', strtotime($innerItem['start_date']));
                array_push($this->dateInArray, $in);
                array_push($this->dateOutArray, $out);
                $detail = [
                    'combination_id' => $item['product_id'],
                    'type' => $innerItem['type'],
                    'supplier_group_id' => $info['supplier_group_id'],
                    'supplier_id' => $info['suppliertable_id'],
                    'item_id' => $innerItem['item_id'],
                    'qty' => (int)$innerItem['qty'],
                    'traveler_type' => $innerItem['traveler_type'],
                    'date_in' => $in,
                    'date_out' => $out,
                    'snapshot' => json_encode($info),
                    'settlement_type' => isset($info['supplierSchedule']) ? $info['supplierSchedule']['remittance_type'] : $info['supplierTraffic']['remittance_type'],
                    'remark' => isset($this->params['remark']) ? $this->params['remark'] : ''
                ];
                $days = $innerItem['type'] == 1 ? $info['days'] : 1;
                $this->handleCartItem($detail, $info, $innerItem, $innerItem['qty'], $days, $this->products[$pid]['detail'][$innerItem['detail_id']]['is_return'], $innerItem['traveler_type']);
                array_push($this->orderDetails, $detail);
                $price_i = $detail['pack_price'] / ($info['useqty'] ?? 1) / $innerItem['qty'] / 100;
                $amount_i = $detail['amount'] / 100;
                $num = $innerItem['qty'] * ($info['useqty'] ?? 1);

                array_push($items, [
                    'name' => isset($info['supplierSchedule']) ? $info['supplierSchedule']['name'] : $info['supplierTraffic']['name'],
                    'type' => $innerItem['type'],
                    'item' => $info['name'],
                    'item_id' => $info['id'],
                    'traveler_type' => $innerItem['traveler_type'],
                    'date_in' => $innerItem['start_date'],
                    'date_out' => date('Y-m-d', $detail['date_out']),
                    'price_adult' => $innerItem['traveler_type'] == 'adult' ? $price_i : 0,
                    'price_child' => $innerItem['traveler_type'] == 'child' ? $price_i : 0,
                    'amount_adult' => $innerItem['traveler_type'] == 'adult' ? $amount_i : 0,
                    'amount_child' => $innerItem['traveler_type'] == 'child' ? $amount_i : 0,
                    'num_adult' => $innerItem['traveler_type'] == 'adult' ? $num : 0,
                    'num_child' => $innerItem['traveler_type'] == 'child' ? $num : 0,
                    'symbol' => $currency[$this->currency]['symbol']

                ]);
                $amount += $detail['amount'];
                $price += $detail['pack_price'];
            }
            if (!empty($this->rooms)) {
                $this->checkRoomQty();
            }

            // 前端显示费用详情
            array_push($this->itemDetail,
                [
                    'product_id' => $item['product_id'],
                    'qty' => 1,
                    'currency' => $this->currency,
                    'symbol' => $currency[$this->currency]['symbol'],
                    'price' => $price / 100,
                    'amount' => $amount / 100,
                    'items' => $this->handleItems($items)
                ]
            );


            // 订单总价
            $this->orderAmount += $amount;

            $discount = 0;
            // 优惠信息
            if (time() < strtotime($this->products[$pid]['discount_end'])) {
                $single = (int)bcmul(
                    (string)$this->products[$pid]['discount'] * 100,
                    (string)$this->exchange_rate[$this->products[$pid]['currency_id']][$this->currency]

                );
                $discount = (int)bcmul((string)1, (string)$single);

                $discount_detail_item = [
//                    'order_id' => $this->order_id,
                    'type' => 1,
                    'origin_id' => $pid,
                    'num' => 1,
                    'name' => $this->products[$pid]['name_tc'],
                    'currency' => $this->currency,
                    'symbol' => $currency[$this->currency]['symbol'],
                    'single_discount' => $single,
                    'single_discount_yen' => $single / 100,
                    'discount' => $discount,
                    'discount_yen' => (int)bcdiv((string)$discount, (string)100),
                ];
                array_push($this->discount_detail, $discount_detail_item);

                $this->orderDiscount += $discount_detail_item['discount'];

            }

            // ITEMS
            $item = [
                'type' => CN::TYPE_COMBINATION,
                'item_id' => $pid,
                'qty' => (int)$item['qty'],
                'snapshot' => json_encode($this->products[$pid]),
                'currency_id' => $this->products[$pid]['currency_id'],
                'symbol' => $currency[$this->products[$pid]['currency_id']]['symbol'],
                'price' => $price,
                'amount' => $amount,
                'discount' => $discount,
            ];
            array_push($this->itemDetails, $item);
        }


        $this->fields = array_unique($this->fields, SORT_STRING);

        $this->handleTypeContent($types_array);
        $this->is_cal && $this->checkFields();

        return $this;
    }

    /**
     * @param $items
     * @return array
     */
    public function handleItems($items): array
    {
        $ret = [];
        $weekArray = array('周日', '周一', '周二', '周三', '周四', '周五', '周六');
        foreach ($items as $k => $item) {
            $key = md5($item['type'] . '_' . $item['item_id'] . '_' . $item['date_in']);
            $item['date_in_w'] = $weekArray[date('w', strtotime($item['date_in']))];
            $item['date_out_w'] = $weekArray[date('w', strtotime($item['date_out']))];
            $item['id'] = [$k];
            if (!isset($ret[$key])) {
                $ret[$key] = $item;
            } else {
                if (!in_array($k, $ret[$key]['id'])) {
                    $ret[$key]['amount_adult'] += $item['amount_adult'];
                    $ret[$key]['amount_child'] += $item['amount_child'];
                    $ret[$key]['price_adult'] += $item['price_adult'];
                    $ret[$key]['price_child'] += $item['price_child'];
                    $ret[$key]['num_adult'] += $item['num_adult'];
                    $ret[$key]['num_child'] += $item['num_child'];
                    array_push($ret[$key]['id'], $k);
                }
            }
        }
        foreach ($ret as &$item) {
            unset($item['id'], $item['item_id']);
        }
        return array_values($ret);
    }


    /**
     * @return int|mixed
     */
    public function AddToMemberCart(): int
    {
        $cartModel = MemberCartModel::getInstance();
        $ext = $cartModel->where('company_id', $this->company_id)
            ->where('member_id', $this->member_id)
            ->where('product_id', array_key_first($this->params['product']))
            ->findOrEmpty()->toArray();
        if (empty($ext)) {
            $ret = MemberCartModel::create(
                array_merge(
                    $this->params['product'][array_key_first($this->params['product'])],
                    [
                        'member_id' => $this->member_id, 'company_id' => $this->company_id,
                        'snapshot' => json_encode($this->products[array_key_first($this->params['product'])])
                    ]
                )
            );
            return $ret->id;
        } else {
            return 0;
        }
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function getProduct(): IndependentOrderService
    {
        $pdModel = IndependentCombination::getInstance();
        $this->params['product'] = array_column($this->params['product'], null, 'product_id');
        foreach ($this->params['product'] as $param) {
            $product = $pdModel->where('id', $param['product_id'])
                ->with(['detail', 'fields'])
                ->where('is_del', 0)
                ->findOrEmpty()->toArray();
            if (empty($product)) {
                BizException::throwException(33001);
            }
            if ($product['status'] == 0) {
                BizException::throwException(33002);
            }
            $this->currency = $product['currency_id'];
            $product['detail'] = array_column($product['detail'], null, 'id');
            $this->products[$param['product_id']] = $product;
        }
        return $this;
    }

    /**
     * @param $detail
     * @param $info
     * @param $item
     * @param $item_num
     * @param $days
     * @param $is_return
     * @param $traveler_type
     */
    public function handleCartItem(&$detail, $info, $item, $item_num, $days, $is_return, $traveler_type)
    {
        $multiple = $is_return == 0 ? 1 : 2;
        // plan
        $this->getPlanDate($detail['type'], $item['start_date'], $info, $item_num, $days);
        // main
        $dateArr = [];
        $detail['cost'] = $detail['amount'] = 0;
        switch ($detail['type']) {
            case CN::TYPE_HOTEL:
                $price = $this->priceService->getPrice(SupplierSpecialDayFee::TYPE_PACKAGE, (int)$detail['item_id'], [date('Y-m-d', $detail['date_in'])]);
                if (!empty($info['item'])) {
                    $days = $days < 2 ? 2 : $days;
                    for ($d = 1; $d < $days; $d++) {
                        $date = strtotime('+' . ($d - 1) . 'day', $detail['date_in']);
                        foreach ($info['item'] as $item) {
                            if ($item['type'] == 5 && $d == $item['days']) {
                                $tmp = [
                                    'type' => CN::TYPE_HOTEL,
                                    'supplier' => $info['suppliertable_id'],
                                    'room' => $item['index_id'],
                                    'qty' => $item_num,
                                    'date' => Date('Y-m-d', $date)
                                ];
                                $this->rooms[$d][] = $tmp;
                                array_push($dateArr, Date('Y-m-d', $date));
                            }
                        }
                    }
                }
                $detail['cost'] = (int)bcmul($price['data'][date('Y-m-d',
                        $detail['date_in'])]['dispersion_room_reserve_fee'] * 100, $item_num);
                $detail['price'] = (int)bcmul($price['data'][date('Y-m-d',
                        $detail['date_in'])]['dispersion_room_fee'] * 100,
                    $this->exchange_rate[$info['supplierSchedule']['default_currency_id']][$this->currency]);
                $detail['pack_price'] = (int)bcmul($price['data'][date('Y-m-d',
                        $detail['date_in'])]['dispersion_room_fee'] * 100,
                    (string)bcmul(
                        (string)$item_num,
                        (string)$this->exchange_rate[$info['supplierSchedule']['default_currency_id']][$this->currency]
                    )
                );

                $detail['amount'] = (int)bcmul($detail['price'], $item_num, 0);
                break;
            case CN::TYPE_SCENE:
                $key = $traveler_type == 'adult' ? 'dispersion_adult_fee' : 'dispersion_child_fee';
                $date = Date('Y-m-d', $detail['date_in']);
                $priceArr = $this->priceService->getPrice(SupplierSpecialDayFee::TYPE_SCENIC, (int)$info['id'], [$date]);
                $detail['cost'] = (int)bcmul($priceArr['data'][$date]['adult_fee'], $item_num, 2) * 100;
                $detail['price'] = (int)bcmul(
                    $priceArr['data'][$date][$key] * 100,
                    $this->exchange_rate[$info['supplierSchedule']['default_currency_id']][$this->currency]
                );
                $detail['pack_price'] = (int)bcmul(
                    (string)$priceArr['data'][$date][$key] * 100,
                    (string)bcmul(
                        (string)$item_num,
                        (string)$this->exchange_rate[$info['supplierSchedule']['default_currency_id']][$this->currency]
                    )
                );
                $detail['amount'] = (int)bcmul($detail['price'], (string)$item_num, 2);
                break;
            case CN::TYPE_CAR:
                $fieldPrefix = in_array(date('w', $detail['date_in']), [7, 0]) ? 'dispersion_weekend' : 'dispersion_weekday';
                $detail['cost'] = (int)bcmul($info[$fieldPrefix . '_cost'], $item_num, 2) * 100 * $multiple;
                $detail['price'] = (int)bcmul((string)$info[$fieldPrefix . '_price'], (string)$this->exchange_rate[$info['currency_type']][$this->currency], 2) * 100 * $multiple;
                $detail['pack_price'] = (int)bcmul((string)$info[$fieldPrefix . '_price'] * 100,
                    (string)bcmul(
                        (string)$item_num,
                        (string)$this->exchange_rate[$info['currency_type']][$this->currency]
                    )
                );
                $detail['amount'] = (int)bcmul((string)$detail['price'], (string)$item_num, 2);
                break;
            case CN::TYPE_SHIP:
                $date = Date('Y-m-d', $detail['date_in']);
                $priceArr = $this->priceService->getPrice(SupplierSpecialDayFee::TYPE_SHIP_ROUTE, (int)$info['id'], [$date]);
                $detail['cost'] = (int)bcmul((string)$priceArr['data'][$date]['adult_fee'], (string)$item_num, 2) * 100 * $multiple;
                $detail['price'] = (int)bcmul((string)$priceArr['data'][$date]['adult_fee'], (string)$this->exchange_rate[$info['default_currency_id']][$this->currency], 2) * 100 * $multiple;
                $detail['pack_price'] = (int)bcmul(
                    (string)$priceArr['data'][$date]['adult_fee'] * 100,
                    (string)bcmul(
                        (string)$item_num,
                        (string)$this->exchange_rate[$info['default_currency_id']][$this->currency]
                    )
                );
                $detail['amount'] = (int)bcmul((string)$detail['price'], (string)$item_num, 2);
                break;
            case CN::TYPE_AIR:
                $detail['cost'] = (int)bcmul((string)$info['fee'], (string)$item_num, 2) * $multiple;
                $detail['price'] = (int)bcmul((string)$info['price'], (string)$this->exchange_rate[$info['price_currency']][$this->currency], 2) * 100 * $multiple;
                $detail['pack_price'] = (int)bcmul(
                    (string)$info['price'] * 100,
                    (string)bcmul(
                        (string)$item_num,
                        (string)$this->exchange_rate[$info['price_currency']][$this->currency]
                    )
                );
                $detail['amount'] = (int)bcmul((string)$detail['price'], (string)$item_num, 2);
                break;
            case CN::TYPE_INSURANCE:
                $days += 1;
                $premium = InsurancePlan::premium($days, $info);
                if (fmod((float)$premium, 1) <= 0) {
                    $premium = (int)$premium;
                }
                $detail['cost'] = (int)bcmul($premium, $item_num * $this->exchange_rate[$info['currency_id']][$this->currency], 2) * 100;
                $detail['price'] = (int)bcmul($premium,
                        $this->exchange_rate[$info['currency_id']][$this->currency], 2) * 100;
                $detail['pack_price'] = (int)bcmul($premium, $item_num *
                        $this->exchange_rate[$info['currency_id']][$this->currency], 2) * 100;
                $detail['amount'] = (int)bcmul($premium, $item_num * $this->exchange_rate[$info['currency_id']][$this->currency], 2) * 100;
                break;
            default:
                break;
        }
        $detail['commission_currency'] = isset($info['commission_currency']) ? $info['commission_currency'] : 1;
        $fee = isset($info['commission_fee']) ? $info['commission_fee'] * $item_num : 0;
        if ($detail['commission_currency'] == 1) {
            $detail['commission_fee_yen'] = $fee;
            $detail['commission_fee_hkd'] = 0;
        } elseif ($detail['commission_currency'] == 2) {
            $detail['commission_fee_yen'] = 0;
            $detail['commission_fee_hkd'] = $fee;
        }
    }

    public function checkRoomQty()
    {
        foreach ($this->rooms as $roomA) {
            foreach ($roomA as $room) {
                $available = $this->stockService->getQty($room['room'], $this->stockService::FIELD_QTY_INDP, $room['date']);
                if ($available < $room['qty']) {
                    BizException::throwException(33003, 'Room:' . $room['room'] . '庫存不足');
                }
            }
        }
    }

    /**
     * @throws \ErrorException
     */
    public function useRoomQty()
    {
        foreach ($this->rooms as $roomD) {
            foreach ($roomD as $room) {
                if ($this->stockService->getQty($room['room'], StockService::FIELD_QTY_INDP, $room['date']) < $room['qty']) {
                    throw new \ErrorException('Room:' . $room['room'] . '库存不足');
                }
                $this->stockService->reduce($room['room'], StockService::FIELD_QTY_INDP, $room['date'], $room['qty']);
                Log::write('库存使用:' . $room['room'] . '-' . $room['date'] . '-' . $room['qty']);
            }
        }
    }

    /**
     * 訂單和訂單詳情信息
     * @return $this
     */
    public function handleOrder(): IndependentOrderService
    {
        $this->customer = $this->params['customer'];
        $this->contact = $this->params['contact'];
        try {
            $this->setOrderInfo(
                [
                    'origin', 'type', 'order_num', 'company_id', 'staff_id', 'amount',
                    'date_in', 'date_out',
                    'discount', 'real_amount',
                    'remark', 'remark_inner', 'member_id',
                    'contact_last_name', 'contact_first_name', 'contact_phone', 'status', 'currency_id',
                    'contact',
                    'type_content', 'create_at', 'contact_title', 'branch_id', 'guest_num'
                ],
                [
                    $this->origin, $this->device_id, $this->orderNum, $this->company_id, 0, $this->orderAmount,
                    date('Y-m-d', min($this->dateInArray)), date('Y-m-d', max($this->dateOutArray)),
                    $this->orderDiscount, $this->orderAmount - $this->orderDiscount, empty($this->params['remark']) ? '' : $this->params['remark'],
                    '', $this->member_id,
                    $this->contact['lastName_cn'], $this->contact['firstName_cn'],
                    $this->contact['phone'], 1, $this->currency,
                    $this->contact['lastName_cn'] . ' ' . $this->contact['firstName_cn'],
                    $this->type_content, time(), $this->contact['title'] ?? 1,
                    Branch::getBranchId(null, 0, (int)$this->company_id), count($this->customer)
                ]
            );
            $model = new IndependentOrder();
            $this->order_id = $model->insertGetId($this->orderInfo);
            if ($this->order_id) {
                foreach ($this->orderDetails as &$det) {
                    $det['order_id'] = $this->order_id;
                }
            }
        } catch (\Exception $e) {
            Log::info($e->getFile() . $e->getLine() . $e->getMessage());
//            dd($e->getFile() . $e->getLine() . $e->getMessage());
        }
        return $this;
    }

    /**
     * @param $fields
     * @param $values
     */
    private function setOrderInfo($fields, $values)
    {
        if (!empty($fields) && !empty($values) && (count($fields) == count($values))) {
            for ($i = 0; $i < count($fields); $i++) {
                $this->orderInfo[$fields[$i]] = $values[$i];
            }
        }
    }

    private function handleCustomer(): IndependentOrderService
    {
        try {
            $credentials = config('system.credentials');
            if (!empty($this->customer)) {
                // member
                $member = MemberModel::getInstance();
                $memberInfo = MemberModel::findByPhone($this->company_id, $this->contact['phone'], '*', false, 0);
                if (is_null($memberInfo)) {
                    $memberInfo = MemberModel::firstOrCreateByPhone(
                        $this->company_id,
                        $this->contact['phone'],
                        [
                            'phone' => $this->contact['phone'],
                            'area_code' => $this->contact['area_code'],
                            'company_id' => $this->company_id
                        ]
                    );
                }
                foreach ($this->customer as &$cus) {
                    $cus['title'] = !empty($cus['title']) ? $cus['title'] : 1;
                    $cus['gender'] = !empty($cus['title']) && $cus['title'] > 1 ? 2 : 1;
                    $cus['order_id'] = $this->order_id;
                    $cus['prefix'] = $cus['area_code'];
                    $cus['credentials'] = $credentials[$cus['credentials_type']]['code'];
                    $cus['identity_id'] = $cus['credentials_number'];
                    if ($this->contact['phone'] != $cus['phone']) {
                        $memberInfo = MemberModel::findByPhone($this->company_id, $cus['phone'], 'id', false, 0);
                        if (is_null($memberInfo)) {
                            $memberInfo = MemberModel::firstOrCreateByPhone(
                                $this->company_id,
                                $cus['phone'],
                                [
                                    'phone' => $cus['phone'],
                                    'area_code' => $cus['prefix'],
                                    'company_id' => $this->company_id
                                ]
                            );
                        }
                    }
                    (isset($cus['birthday']) && $cus['birthday'] != '') && $cus['birthday'] = strtotime($cus['birthday']);
                    (isset($cus['valid']) && $cus['valid'] != '') && $cus['valid'] = strtotime($cus['valid']);
                }
                $customerModel = new IndependentOrderCustomer();
                $customerModel->saveAll($this->customer);
            }
        } catch (\Exception $d) {
            log::info($d->getFile() . $d->getLine() . $d->getMessage());
        }
        return $this;
    }

    /**
     * 得到優惠信息
     * 暫時只有優惠組合產生的優惠
     * @return $this
     * @throws \Exception
     */
    private function SaveDiscount(): IndependentOrderService
    {
        try {
            if (!empty($this->discount_detail)) {
                $discountModel = new IndependentOrderDiscount();
                $discountModel->saveAll($this->discount_detail);
            }
        } catch (\Exception $e) {
            Log::info($e->getFile() . $e->getLine() . $e->getMessage());
        }
        return $this;
    }


    /**
     * @throws \Exception
     */
    private function savePlan()
    {
        foreach ($this->dateList as $line) {
            foreach ($line as $item) {
                $item['order_id'] = $this->order_id;
                $planModel = new IndependentOrderPlan();
                $planModel->save($item);
            }
        }
    }

    /**
     * 訂單行程計算
     * @param $type
     * @param $date_in
     * @param $info
     * @param $item_num
     * @param $days
     */
    public function getPlanDate($type, $date_in, $info, $item_num, $days)
    {
//        dd($info, $date_in, , $item_num, $days);
        if ($type == CN::TYPE_HOTEL || $type == CN::TYPE_INSURANCE) {
            for ($d = 0; $d < $days - 1; $d++) {
                $date = date('Y-m-d', strtotime('+' . $d . ' days', strtotime($date_in)));
                $line = [
                    'days' => $d + 1,
                    'date' => $date,
                    'snapshot' => json_encode($info),
                    'type' => $type,
                    'item' => $info['id'],
                    'extra' => $info['item'][$d]['index_id'],
                    'qty' => (int)$item_num,
                ];
                $this->dateList[$date][] = $line;
            }

        } else {
            // 其他只有一個時間
            $line = [
                'days' => 1,
                'date' => $date_in,
                'snapshot' => json_encode($info),
                'type' => $type,
                'item' => $info['id'],
                'extra' => 0,
                'qty' => (int)$item_num,
            ];
            $this->dateList[$date_in][] = $line;
        }

        ksort($this->dateList);
    }

    /**
     * TODO
     * ItemDetails
     */
    public function saveItemDetails()
    {
        foreach ($this->itemDetails as &$item) {
            $item['order_id'] = $this->order_id;
            IndependentOrderItems::create($item);
        }
    }

    /**
     * @return int
     * @throws \Exception
     */
    private function saveOrderDetail()
    {
        $detailModel = new IndependentOrderDetail();
        $detailModel->saveAll($this->orderDetails);
        return $this->order_id;
    }

    /**
     * @param $type
     * @param $item
     * @return mixed
     */
    public static function getTypeItemDetail($type, $item)
    {
        try {
            $class = IndependentOrderService::TYPE_MODEL[$type];
            $model = new $class;
            return $model::getTypeItemDetail($item);
        } catch (\Exception $e) {
            throw new \Error($e->getFile() . $e->getLine() . $e->getMessage());
        }
    }

    /**
     * @param $type
     */
    public function handleTypeContent($types)
    {
        $types = array_unique(array_filter($types));
        if (!empty($types)) {
            foreach ($types as $type) {
                $this->type_content += self::TYPE_TO_CONTENT[$type];
            }
        }
    }

    /**
     *
     */
    private function checkFields()
    {
        if (empty($this->fields)) {
            $this->fields = [
                'title', 'firstName_cn', 'lastName_cn', 'area_code', 'phone'
            ];
        }
    }
}