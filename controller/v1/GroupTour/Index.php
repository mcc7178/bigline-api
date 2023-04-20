<?php

namespace app\api\controller\v1\GroupTour;

use app\admin\service\PriceService;
use app\admin\service\StockService;
use app\api\controller\ApiBaseController;
use app\api\job\MemberTraceJob;
use app\api\job\SearchRecordsService;
use app\api\model\app\BannerModel;
use app\api\model\member\MemberCollection;
use app\api\model\member\MemberProductTrace;
use app\api\model\member\MemberSearchModel;
use app\api\service\CommonService;
use app\api\service\GroupTourService;
use app\api\validate\GroupOrderValidator;
use app\facade\Redis;
use comm\constant\CK;
use comm\constant\CN;
use comm\model\member\MemberManagementModel;
use comm\model\order\OrderCustomerModel;
use comm\model\order\OrderModel;
use comm\model\product\ProductBaseModel;
use comm\model\product\ProductCateModel;
use comm\model\product\ProductRegister as ProductRegisterModel;
use comm\model\product\ProductReleaseCutoffItem;
use comm\model\product\ProductReleaseModel;
use comm\model\product\ProductSeriesModel;
use comm\model\product\ProductThemeModel;
use comm\model\supplier\AirportModel;
use comm\model\supplier\RailwayRouteModel;
use comm\model\supplier\RailwayStationModel;
use comm\model\supplier\SupplierAirlineRouteModel;
use comm\model\supplier\SupplierCarModel;
use comm\model\supplier\SupplierLocaltourItemModel;
use comm\model\supplier\SupplierLocaltourRouteModel;
use comm\model\supplier\SupplierMenuModel;
use comm\model\supplier\SupplierPackageModel;
use comm\model\supplier\SupplierRoomModel;
use comm\model\supplier\SupplierScenicSpotItemModel;
use comm\model\supplier\SupplierScheduleModel;
use comm\model\supplier\SupplierShipRouteModel;
use comm\model\supplier\SupplierSpecialDayFee;
use comm\model\system\SystemConfig;
use comm\service\PackageRoomStock;
use think\App;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;
use think\Model;
use think\Request;
use app\api\lib\BizException;

/**
 * 跟團遊首頁控制器
 * Class Index
 * @package app\api\controller\v1\GroupTour
 */
class Index extends ApiBaseController
{
    private $service;

    private $expire = 1 * 60 * 60;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->service = new GroupTourService($this->company_id, $this->request->uid, (int)$app->request->header('Device', 3));
    }

    /**
     * 主題列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function theme()
    {
        $request = $this->request;
        $series_id = $request->get('series_id');
        $field = 'theme_id,theme_name,theme_img';
        $model = ProductThemeModel::field($field)
            ->where('company_id', $this->company_id)
            ->where(['status' => 1, 'is_del' => 0]);
        if (!empty($series_id)) {
            $theme_ids = ProductSeriesModel::where('series_id', $series_id)->value('theme_ids', '');
            $ids = json_decode($theme_ids, true);
            if ($ids) {
                $model->where('theme_id', 'in', $ids);
            } else {
                $model->where('theme_id', 0);
            }
        }
        $data = $model->order('sort', 'desc')
            ->select()
            ->map(function (Model $item) {
                $item->theme_img .= env('ALIYUN_OSS.appResizes', '');
                return $item;
            })
            ->toArray();
        return $this->apiResponse(function () use ($data) {
            return $data;
        });
    }

    /**
     * banner輪播圖
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function banner()
    {
        return $this->apiResponse(function () {
            return BannerModel::field('id,picture,bannerable_id,bannerable_type')
                ->where('company_id', $this->company_id)
                ->where('module', BannerModel::MODULE_GROUP)
                ->where(['location' => 3, 'bannerable_type' => 'group', 'delete_time' => 0])
                ->order('sort', 'desc')
                ->select()
                ->map(function (Model $item) {
                    $item->picture .= env('ALIYUN_OSS.appResizes', '');
                    return $item;
                })
                ->toArray();
        });
    }

    /**
     * 系列列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function series()
    {
        return $this->apiResponse(function () {
            $field = 'series_id,series_name,series_type';
            return ProductSeriesModel::field($field)
                ->where('company_id', $this->company_id)
                ->where(['status' => 1, 'is_del' => 0, 'series_type' => 0])
                ->order('sort', 'desc')
                ->select()->toArray();
        });
    }

    /**
     * 搜索條件聯動-系列->地區
     * @return mixed
     */
    public function destination()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $where = [
                ['pid', '=', 0],
                ['company_id', '=', $this->company_id],
                ['to_city', '<>', '']
            ];
            if (!empty($request->get('series_id'))) {
                $where[] = ['series_id', '=', $request->get('series_id')];
            }
            $data = ProductBaseModel::where($where)->column('to_city');
            $list = [];
            if ($data) {
                $list = ProductCateModel::where('cate_code', 'in', $data)
                    ->field('cate_id,cate_code,cate_pid,cate_name,area_code')
                    ->select()
                    ->order('weight', 'desc')
                    ->map(function (Model $item) {
                        $item->picture .= env('ALIYUN_OSS.appResizes', '');
                        return $item;
                    })
                    ->toArray();
            }
            return array_values($list);
        });
    }

    /**
     * 搜索前置
     * @return mixed
     */
    public function search()
    {
        $params = $this->request->get();
        return $this->apiResponse(function () use ($params) {
            return $this->service->search($params);
        });
    }

    /**
     * 跟團遊列表
     * @return mixed
     * @throws \think\db\exception\DbException
     */
    public function list()
    {
        $get = $this->request->get();
        return $this->cache()->apiResponse(function () use ($get) {
            Log::write('GroupTourListGet:' . json_encode($get, JSON_UNESCAPED_UNICODE));

            $where = [
                ['b.pid', '=', 0],
                ['b.status', '=', 1],
                ['b.is_del', '=', 0],
                ['b.company_id', '=', $this->company_id],
//            ['b.latest_date', '>=', strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')))],
                ['s.status', '=', 1],
                ['s.is_del', '=', 0],
                ['s.series_type', '=', 0],
                ['s.company_id', '=', $this->company_id],
            ];

            //名稱
            if (!empty(trim($get['product_name']))) {
                $product_name = trim($get['product_name']);
                $whereRaw[] = ['b.product_name|b.product_code', 'like', "%$product_name%"];
                // 搜索记录
                if ($this->request->uid) {
                    queue(SearchRecordsService::class,
                        [
                            'type' => MemberSearchModel::TYPE_GROUP,
                            'member_id' => $this->request->uid,
                            'company_id' => $this->company_id,
                            'words' => $get['product_name']
                        ]
                    );
                }
            } else {
                $whereRaw[] = ['b.product_name', '<>', ''];
            }

            //系列
            if (!empty($get['series_id'])) {
                $where[] = ['b.series_id', '=', $get['series_id']];
            }

            //主題
            if (!empty($get['theme_id'])) {
                $where[] = ['b.theme_id', '=', $get['theme_id']];
            }

            //行程天数
            if (!empty($get['total_days_from'])) {
                $where[] = ['b.total_days', '>=', $get['total_days_from']];
            }
            if (!empty($get['total_days_to'])) {
                $where[] = ['b.total_days', '<=', $get['total_days_to']];
            }

            //目的地城市
            if (!empty($get['to_city'])) {
                $where[] = ['b.to_city', '=', $get['to_city']];
            }

            //出發日期
            if (!empty($get['tour_date'])) {
                $ids = ProductReleaseModel::where([
                    ['tour_date', '=', strtotime($get['tour_date'])],
                    ['member_fee', '>', 0],
                    ['is_cutoff_tour', '=', 0],
                    ['is_internal_sell', '=', 0],
                    ['is_noroom', '=', 0],
                    ['tour_status', '<>', 'canceled'],
                    ['approved', '=', 1],
                ])->column('product_base_id');
                unset($where[0]);
                $where[] = ['b.product_id', 'in', $ids];
            }
            if (!empty($get['product_code'])) {
                $where[] = ['b.product_code', '=', $get['product_code']];
            }
            $where = array_values($where);

            //獲取數據
            $field = [
                'b.product_id', 'b.pid', 'b.product_name', 'b.product_code', 'b.company_id', 'b.series_id', 'b.theme_id',
                'b.total_days', 'b.currency', 'b.picture', 'b.minimum_price', 'b.earliest_date', 'b.latest_date', 'b.group_region',
                's.series_name', 't.theme_name'
            ];
            $list = ProductBaseModel::where($where)
                ->where($whereRaw)
                ->alias('b')
                ->leftjoin(full_table_name(new ProductSeriesModel()) . ' s', 'b.series_id = s.series_id')
                ->leftjoin(full_table_name(new ProductThemeModel()) . ' t', 'b.theme_id = t.theme_id')
                ->field($field)
                ->order('b.product_id desc')
                ->select()
                ->toArray();

            foreach ($list as $key => $item) {
                if (is_null($item['product_id'])) {
                    unset($list[$key]);
                }
                $children = ProductBaseModel::alias('b')
                    ->leftjoin(full_table_name(new ProductSeriesModel()) . ' s', 'b.series_id=s.series_id')
                    ->leftjoin(full_table_name(new ProductReleaseModel()) . ' r', 'r.product_base_id=b.product_id')
                    ->where([
                        ['b.pid', '=', empty($get['tour_date']) ? $item['product_id'] : $item['pid']],
                        ['b.status', '=', 1],
                        ['b.is_del', '=', 0],
                        ['b.company_id', '=', $this->company_id],
                        ['s.status', '=', 1],
                        ['s.is_del', '=', 0],
                        ['s.series_type', '=', 0],
                        ['s.company_id', '=', $this->company_id],
                        ['r.tour_date', '>=', strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')))],
                        ['r.member_fee', '>', 0],
                        ['r.is_cutoff_tour', '=', 0],
                        ['r.is_internal_sell', '=', 0],
                        ['r.is_noroom', '=', 0],
                        ['r.tour_status', '<>', 'canceled'],
                        ['r.approved', '=', 1],
                    ])->field('b.product_id,r.tour_date,r.member_fee')->order('r.tour_date', 'asc')->select()->toArray();
                if ($children == false) {
                    unset($list[$key]);
                    continue;
                }
                $list[$key]['earliest_date'] = date('Y-m-d', $children[0]['tour_date']);
                $list[$key]['latest_date'] = date('Y-m-d', $children[count($children) - 1]['tour_date']);
                $base_ids = array_column($children, 'product_id');
                $order_ids = OrderModel::where('product_release_id', 'in', ProductReleaseModel::where('product_base_id', 'in', $base_ids)->column('id'))->column('id');
                $list[$key]['sold_qty'] = OrderCustomerModel::where([
                    ['order_id', 'in', $order_ids],
                    ['status', '=', 1],
                ])->count();
                $fee = array_column($children, 'member_fee');
                $list[$key]['view_num'] = MemberProductTrace::where('product', 'in', $base_ids)->where('productable', 'group')->sum('times');
                $list[$key]['currency'] = $item['currency'] == 1 ? '¥' : '$';
                if (empty($get['tour_date'])) {
                    $list[$key]['product_id'] = ProductBaseModel::where(['company_id' => $this->company_id, 'status' => 1, 'is_del' => 0, 'pid' => $item['product_id']])->order('product_id', 'desc')->value('product_id');
                }
                $list[$key]['picture'] .= env('ALIYUN_OSS.appResizes', '');
                $minimum_price = $fee ? ceil((float)bcdiv(min($fee), 100)) : 0;
                $list[$key]['minimum_price'] = $minimum_price;
                if (!empty($get['minimum_price_from'])) {
                    if ($minimum_price < $get['minimum_price_from']) {
                        unset($list[$key]);
                    }
                }
                if (!empty($get['minimum_price_to'])) {
                    if ($minimum_price > $get['minimum_price_to']) {
                        unset($list[$key]);
                    }
                }
            }
            $per_page = (int)input('limit', 10);
            $current_page = (int)input('page', 1);

            //1综合排序,2价格降序,3价格升序,4销量优先,5好评优先
            $result = array_slice(array_values($list), ($current_page - 1) * $per_page, $per_page);
            if (!empty($get['sort'])) {
                switch ($get['sort']) {
                    case 1:
                        array_multisort(array_column($result, 'product_id'), SORT_DESC, SORT_NUMERIC, $result);
                        break;
                    case 2:
                        array_multisort(array_column($result, 'minimum_price'), SORT_DESC, SORT_NUMERIC, $result);
                        break;
                    case 3:
                        array_multisort(array_column($result, 'minimum_price'), SORT_ASC, SORT_NUMERIC, $result);
                        break;
                    case 4:
                    case 5:
                        array_multisort(array_column($result, 'sold_qty'), SORT_DESC, SORT_NUMERIC, $result);
                        break;
                }
            }
            $data = [
                'total' => count($list),
                'per_page' => $per_page,
                'current_page' => $current_page,
                'last_page' => ceil(count($list) / $per_page),
                'data' => $result
            ];

            return $data;
        });
    }

    /**
     * 旅行團詳情
     * @return array|\think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function detail()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $member_id = $request->user()->id;
            $id = $request->get('product_id');
            $detail = ProductBaseModel::where(['company_id' => $this->company_id, 'status' => 1, 'is_del' => 0, 'product_id' => $id])
                ->with([
                    'character',
                    'insurPlan' => function ($query) {
                        $query->field('id,name,additional_premium,insured_date');
                    },
                    'theme' => function ($query) {
                        $query->field('theme_name,theme_id');
                    },
                    'series' => function ($query) {
                        $query->field('series_name,series_id');
                    },
                    'attachment' => function ($query) {
                        $query->field('attachmentable_id,att_dir');
                    },
                    'a4' => function ($query) {
                        $query->field('id,product_id,filename,document_filename')->where('status', 'enable')->order('update_time', 'desc')
                            ->whereTime('effective_start_date', '<=', time())
                            ->whereTime('effective_end_date', '>=', time())
                            ->limit(1);
                    },
                    'schedule' => function ($query) {
                        $query->with([
                            'supplier.package.item', 'room', 'package.item'
                        ]);
                    },
                    'traffic' => function ($query) {
                        $query->withoutField('driver_info,bus_info');
                    },
                    'release.base'
                ])
                ->findOrFail();
            $detail['roomInfo'] = CommonService::getUpgradePackage($detail, $detail->release->tour_date, $detail->currency);
            $detail = $detail->toArray();
            $detail['symbol'] = $detail['currency'] == 1 ? '¥' : '$';
            $detail['exchangeRate'] = self::getExchangeRate(2, 1);

            //地點信息
            $locationDict = ProductCateModel::where('cate_code', 'in', [$detail['from_city'], $detail['to_city']])->column('cate_name,cate_code', 'cate_code');
            $detail['from_city_name'] = $locationDict[$detail['from_city']]['cate_name'] ?? '';
            $detail['to_city_name'] = $locationDict[$detail['to_city']]['cate_name'] ?? '';

            //行程项目
            $key = CK::API_GROUP_TOUR_SCHEDULE . $id;
            if (Redis::exists($key)) {
                $detail['schedule'] = json_decode(Redis::get($key), true);
            } else {
                $schedule = $this->getScheduleInfo($detail);
                Redis::setex($key, $this->expire, json_encode($schedule, JSON_UNESCAPED_UNICODE));
                $detail['schedule'] = $schedule;
            }
            $detail['member_config'] = config('system.member_config');
            $detail['picture'] .= env('ALIYUN_OSS.appResizem', '');
            foreach ($detail['attachment'] as &$at) {
                $at['att_dir'] .= env('ALIYUN_OSS.appResizem', '');
            }

            unset($detail['release'], $detail['parent']);
            $pid = ProductBaseModel::where('product_id', $id)->value('pid');
            $exist = MemberCollection::where(['member_id' => $member_id, 'product' => $pid, 'productable' => 'group'])->find();
            $detail['collection'] = $exist ? 1 : 0;

            $baseList = $this->getBaseList($id);
            $ids = array_column($baseList, 'product_id');
            $where = [
                ['tour_date', '>=', strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')))],
                ['member_fee', '>', 0],
                ['is_cutoff_tour', '=', 0],
                ['is_internal_sell', '=', 0],
                ['is_noroom', '=', 0],
                ['tour_status', '<>', 'canceled'],
                ['approved', '=', 1],
                ['product_base_id', 'in', $ids],
            ];
            $member_fee = ProductReleaseModel::where($where)->column('member_fee,adult_fee,child_fee,baby_fee');
            if (!$member_fee) $member_fee = ['member_fee' => 0, 'adult_fee' => 0, 'child_fee' => 0, 'baby_fee' => 0];
            $detail['minimum_price'] = ceil((float)bcdiv(min(array_column($member_fee, 'member_fee')), 100));
            $detail['maximum_price'] = ceil((float)bcdiv(max(array_column($member_fee, 'member_fee')), 100));
            $detail['adult_fee'] = ceil((float)bcdiv(min(array_column($member_fee, 'adult_fee')), 100));
            $detail['child_fee'] = ceil((float)bcdiv(min(array_column($member_fee, 'child_fee')), 100));
            $detail['baby_fee'] = ceil((float)bcdiv(min(array_column($member_fee, 'baby_fee')), 100));

            // 异步处理访问记录
            queue(MemberTraceJob::class, [
                'member_id' => $this->request->uid ?: 0,
                'productable' => MemberProductTrace::TYPE_GROUP,
                'product' => $detail['pid'] ?: 0
//            'product' => $id
            ]);


            return $detail;
        });
    }

    /**
     * 某天的團數據
     * @return mixed
     */
    public function groups()
    {
        $request = $this->request;
        $product_id = $request->get('product_id');
        $tour_date = $request->get('tour_date');
        if (empty($product_id) || empty($tour_date)) {
            $this->response->errorBadRequest('參數錯誤');
        }

        //團信息
        $where = [
            ['b.status', '=', 1],
            ['b.is_del', '=', 0],
            ['b.company_id', '=', $this->company_id],
            ['b.pid', '=', ProductBaseModel::where('product_id', $product_id)->value('pid')],
            ['r.tour_date', '=', strtotime($tour_date)],
            ['r.is_cutoff_tour', '=', 0],
            ['r.is_internal_sell', '=', 0],
            ['r.is_noroom', '=', 0],
            ['r.approved', '=', 1],
            ['r.tour_status', '<>', 'canceled'],
            ['r.member_fee', '>', 0],
            ['s.status', '=', 1],
            ['s.is_del', '=', 0],
            ['s.series_type', '=', 0],
            ['s.company_id', '=', $this->company_id]
        ];
        $field = "b.product_id,b.product_name,b.currency,b.pid,b.m_group_info,r.product_base_id,r.car_no,r.tour_date,r.member_fee,r.adult_fee,";
        $field .= 'r.child_fee,r.baby_fee,r.sold_qty,r.tour_date,b.customer_num,b.customer_min,r.id as product_release_id,r.is_guarantee_confirm_tour,';
        $field .= 'b.tip_money,b.tip_type,b.tip_days,b.tip_currency';
        $data = ProductReleaseModel::alias('r')
            ->leftjoin(full_table_name(new ProductBaseModel()) . ' b', 'r.product_base_id = b.product_id')
            ->leftjoin(full_table_name(new ProductSeriesModel()) . ' s', 'b.series_id = s.series_id')
            ->where($where)
            ->field($field)
            ->select()
            ->map(function ($item) {
                $item['m_group_info'] = json_decode($item['m_group_info'], true);
                $item['currency'] = $item['currency'] == 1 ? '¥' : '$';
                $item['status'] = CommonService::group_status($item['sold_qty'], $item['customer_num'], $item['customer_min']);
                $item['tip_money'] = ceil((float)bcdiv($item['tip_money'], 100));
                $item['member_fee'] = ceil($item['member_fee']);
                $item['child_fee'] = ceil($item['child_fee']);
                $item['baby_fee'] = ceil($item['baby_fee']);
                $item['adult_fee'] = ceil($item['adult_fee']);
                if ($item['is_guarantee_confirm_tour'] == 1) $item['status'] = 3;//保证成团=已成团
                return $item;
            })
            ->toArray();
        return $this->apiResponse(function () use ($data) {
            return $data;
        });
    }

    /**
     * 商品詳情-月度價格
     * @param int $product_id
     * @param int $is_login
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function month_price(int $product_id, int $is_login = 0)
    {
        $result = [];
        $year = date('Y');
        $month = date('m');
        $months = [];
        for ($i = 1; $i <= 12; $i++) {
            if ($month > 12) {
                $year += 1;
                $month = 1;
            }
            $months[] = date('Y-m', strtotime("{$year}-{$month}"));
            ++$month;
        }
        $baseList = $this->getBaseList($product_id);
        $symbol = $baseList[0]['currency'] == 1 ? '¥' : '$';
        $ids = array_column($baseList, 'product_id');
        $where = [
            ['product_base_id', 'in', $ids],
            ['approved', '=', 1],
            ['is_cutoff_tour', '=', 0],
            ['is_internal_sell', '=', 0],
            ['is_noroom', '=', 0],
            ['tour_status', '<>', 'canceled'],
            ['member_fee', '>', 0],
        ];
        foreach ($months as $date) {
            $start_date = $date . '-01 00:00:00';
            $end_date = date('Y-m-d 23:59:59', strtotime('+1 month -1 day', strtotime($start_date)));
            $tomorrow = strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')));
            $start = (strtotime($start_date) >= $tomorrow) ? strtotime($start_date) : $tomorrow;

            $wherein = array_merge($where, [['tour_date', '>=', $start], ['tour_date', '<=', strtotime($end_date)]]);
            $price = ProductReleaseModel::where($wherein)->min('member_fee');
            if ($price) {
                $result[] = [
                    'date' => $date,
                    'price' => ceil(bcdiv($price, 100)),
                    'symbol' => $symbol
                ];
            }
        }

        return $this->apiResponse(function () use ($result) {
            return array_values($result);
        });
    }

    /**
     * 產品價格表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function price_list()
    {
        $request = $this->request;
        $product_id = $request->get('product_id', 0);
        $date = $request->get('date', '');
        if (empty($product_id) || empty($date)) {
            $this->response->errorBadRequest('參數錯誤');
        }

        //日期數組
        $date = date('Y-m', strtotime($date));
        $start_date = date("$date-01 00:00:00");
        $end_date = date('Y-m-d 23:59:59', strtotime('+1 month -1 day', strtotime($start_date)));
        $end_day = (array_reverse(explode('-', $end_date)))[0];

        //獲取數據
        $tomorrow = strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')));
        $start = (strtotime($start_date) >= $tomorrow) ? strtotime($start_date) : $tomorrow;
        $where = [
            ['tour_date', '>=', $start],
            ['tour_date', '<=', strtotime($end_date)],
            ['approved', '=', 1],
            ['is_cutoff_tour', '=', 0],
            ['is_internal_sell', '=', 0],
            ['is_noroom', '=', 0],
            ['tour_status', '<>', 'canceled'],
            ['member_fee', '>', 0],
        ];

        $baseList = $this->getBaseList($product_id);
        $symbol = $baseList[0]['currency'] == 1 ? '¥' : '$';
        $ids = array_column($baseList, 'product_id');
        $list = ProductReleaseModel::where($where)
            ->where('product_base_id', 'in', $ids)
            ->field('product_base_id,tour_date,member_fee,adult_fee,sold_qty,child_fee,baby_fee,is_guarantee_confirm_tour')
            ->select()
            ->order('member_fee', 'desc')
            ->toArray();
        $list = array_map(function ($item) use ($symbol) {
            $item['currency'] = $symbol;
            $item['member_fee'] = ceil($item['member_fee']);
            $item['adult_fee'] = ceil($item['adult_fee']);
            $item['child_fee'] = ceil($item['child_fee']);
            $item['baby_fee'] = ceil($item['baby_fee']);
            return $item;
        }, $list);
        $list = array_column($list, null, 'tour_date');

        //組裝數據
        $result = [];
        $baseDict = array_column($baseList, null, 'product_id');
        for ($i = 1; $i <= $end_day; $i++) {
            $i = $i < 10 ? '0' . $i : $i;
            $day = "$date-$i";
            $sold_qty = !empty($list[$day]) ? $list[$day]['sold_qty'] : 0;//售賣人數
            $customer_num = !empty($list[$day]) ? ($baseDict[$list[$day]['product_base_id']]['customer_num'] ?? 0) : 0;//收客人数
            $customer_min = !empty($list[$day]) ? ($baseDict[$list[$day]['product_base_id']]['customer_min'] ?? 0) : 0;//成团人数
            $status = CommonService::group_status($sold_qty, $customer_num, $customer_min);
            if ($list[$day]['is_guarantee_confirm_tour'] == 1) $status = 3;//保证成团=已成团
            $status = isset($list[$day]) ? $status : 0;
            $merge = [
                'currency' => $symbol,
                'status' => $status,
                'sold_qty' => $sold_qty,
                'customer_num' => $customer_num,
                'customer_min' => $customer_min,
            ];
            unset($list[$day]['is_guarantee_confirm_tour']);
            if (isset($list[$day])) {
                $result[$day] = array_merge($list[$day], $merge);
            }
        }

        $result = array_values($result);
        return $this->apiResponse(function () use ($result) {
            return $result;
        });
    }

    /**
     * 最近日期可報名旅行團
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function recent_date()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $product_id = $request->get('product_id', '');
            if ($product_id == false) {
                BizException::throwException(9005, 'product_id');
            }

            $baseList = $this->getBaseList($product_id);
            $baseDict = array_column($baseList, NULL, 'product_id');
            $where = [
                ['tour_date', '>=', strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')))],
                ['is_cutoff_tour', '=', 0],
                ['is_internal_sell', '=', 0],
                ['is_noroom', '=', 0],
                ['product_base_id', 'in', array_keys($baseDict)],
                ['approved', '=', 1],
                ['tour_status', '<>', 'canceled'],
                ['member_fee', '>', 0],
            ];
            $data = ProductReleaseModel::where($where)
                ->with(['base' => function ($query) {
                    $query->field('product_id,currency,customer_num,customer_min,credentials,tip_type,tip_days,tip_currency,tip_money,ad_cost,other_cost');
                }])
                ->field('id,product_base_id,tour_date,member_fee,adult_fee,tour_status,sold_qty,is_guarantee_confirm_tour')
                ->select()
                ->order('tour_date asc,member_fee asc,sold_qty asc')
                ->map(function ($item) use ($baseDict) {
                    $tour_date = explode('-', date('Y-m-d-N', strtotime($item['tour_date'])));
                    $customer_num = $baseDict[$item['product_base_id']]['customer_num'] ?? 0;
                    $customer_min = $baseDict[$item['product_base_id']]['customer_min'] ?? 0;
                    $status = CommonService::group_status($item['sold_qty'], $customer_num, $customer_min);
                    if ($item['is_guarantee_confirm_tour'] == 1) $status = 3;//保证成团=已成团
                    $item['year'] = (int)$tour_date[0];
                    $item['month'] = (int)$tour_date[1];
                    $item['day'] = (int)$tour_date[2];
                    $item['week'] = (int)$tour_date[3];
                    $item['currency'] = $item['currency'] == 1 ? '¥' : '$';
                    $item['tour_status'] = $status;
                    $item['customer_num'] = $customer_num;
                    $item['customer_min'] = $customer_min;
                    $item['credentials'] = $item->base->credentials;
                    $item['tip_type'] = $item->base->tip_type;
                    $item['tip_days'] = $item->base->tip_days;
                    $item['tip_currency'] = $item->base->tip_currency;
                    $item['tip_money'] = ceil($item->base->tip_money);
                    $item['ad_cost'] = ceil($item->base->ad_cost);
                    $item['other_cost'] = ceil($item->base->other_cost);
                    $item['member_fee'] = ceil($item->member_fee);
                    $item['adult_fee'] = ceil($item->adult_fee);
                    unset($item['base']);
                    return $item;
                })
                ->toArray();
            $tour_date = [];
            foreach ($data as $item) {
                if (!in_array($item['tour_date'], $tour_date)) {
                    $tour_date[$item['tour_date']] = $item;
                } else if ($item['member_fee'] < $tour_date[$item['tour_date']]['member_fee']) {
                    $tour_date[$item['tour_date']] = $item;
                }
            }
            ksort($tour_date);
            return array_slice(array_values($tour_date), 0, 10, true);
        });
    }

    /**
     * 產品收藏/取消收藏
     * @return mixed
     * @throws \app\api\lib\BizException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function favorite()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $user_id = $request->user();
            if ($user_id == false) {
                BizException::throwException(401);
            }
            $product_id = $request->post('product_id');
            if ($product_id == false) {
                BizException::throwException(9005, 'product_id');
            }
            $base = ProductBaseModel::field('product_id,status,pid,is_del')->findOrFail($product_id)->toArray();

            if ($base['status'] == 0) {
                BizException::throwException(32001);
            }
            if ($base['is_del'] == 1) {
                BizException::throwException(32002);
            }
            $product_id = $base['pid'] ?: 0;
            $data = MemberCollection::productCollect($user_id->id, MemberCollection::TYPE_GROUP, $product_id);
            return $data;
        });
    }

    /**
     * 創建訂單
     * @param \app\api\Request $request
     * @return mixed
     */
    public function create()
    {
        $request = $this->request;
        $post = $request->post();
        try {
            if (validate(GroupOrderValidator::class)->check($post)) {
                return $this->apiResponse(function () use ($post) {
                    return $this->service->create_order($post);
                });
            }
        } catch (ValidateException $exception) {
            BizException::throwException(9000, $exception->getMessage());
        }
    }

    public function calculate()
    {
        $request = $this->request;
        $post = $request->post();
        try {
            if (validate(GroupOrderValidator::class)->check($post)) {
                return $this->apiResponse(function () use ($post) {
                    $res = $this->service->calculate($post);
                    unset($res['baseInfo'], $res['adult_qty'], $res['child_qty'], $res['baby_qty'], $res['travelers']);
                    return $res;
                });
            }
        } catch (ValidateException $exception) {
            BizException::throwException(9000, $exception->getMessage());
        }
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
     * 座位数据
     * @param Request $request
     * @return mixed
     */
    public function seat_list()
    {
        $request = $this->request;
        $get = $request->get();
        if ($this->validate($get, ['product_release_id' => 'require|integer',])) {
            return $this->apiResponse(function () use ($get) {
                return $this->service->seat_list($get);
            });
        }
    }

    /**
     * 选位
     * @param Request $request
     * @return mixed
     */
    public function select_seat()
    {
        $request = $this->request;
        $post = $request->post();
        if ($this->validate($post, [
            'product_release_id' => 'require|integer',
            'seats' => 'require|array',
            'order_id' => 'require|integer',
        ])) {
            return $this->apiResponse(function () use ($post) {
                return $this->service->select_seat($post);
            });
        }
    }

    /**
     * 獲取行程信息
     * @param $detail
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getScheduleInfo($detail)
    {
        $schedule = [];
        $sep = ":\r\n";
        foreach ($detail['schedule'] as $key => &$item) {
            $type_name_tmp = ProductReleaseCutoffItem::TYPE_NAMES[$item['type']] ?? '';
            $package_name = SupplierPackageModel::where('id', $item['package_id'])->value('name', '');
            $package_name = preg_replace('/\((.+?)\)/', '', $package_name);
            $supplier_name = SupplierScheduleModel::where('id', $item['supplier_id'])->value('name', '');
            if (in_array($item['type'], ['breakfast', 'lunch', 'afternoon_tea', 'afternoon_snack', 'dinner', 'night_snack'])) {
//                $project_name = SupplierMenuModel::where('id', $item['index_id'])->value('name', '');
                if ($item['art_name']) {
                    $project_name = $item['art_name'];
                } else {
                    $project_name = SupplierMenuModel::where('id', $item['index_id'])->value('name', '');
                }
                $type = 'repast';
                $type_name = '餐飲';
                $type_name = $type_name_tmp ? (strpos($schedule[$item['days']][$type], $type_name) === false ? ($type_name . $sep . $type_name_tmp . ':') : $type_name_tmp . ':') : '';
            } else {
                $type = $item['type'];
                $type_name = $type_name_tmp ? ((strpos($schedule[$item['days']][$type], $type_name_tmp) === false) ? $type_name_tmp . $sep : '') : '';
            }
            switch ($item['type']) {
                case 'hotel':
                    $project_name = SupplierRoomModel::where('id', $item['index_id'])->value('name');
                    break;
                case 'scenic_spot_item':
                    $project_name = SupplierScenicSpotItemModel::where('id', $item['index_id'])->value('name', '');
                    break;
                case 'car_company':
                    $project_name = SupplierCarModel::where('id', $item['index_id'])->value('name_tc', '');
                    break;
                case 'ship_company':
                    $project_name = SupplierShipRouteModel::where('id', $item['index_id'])->value('name', '');
                    break;
                case 'local_tour':
                    $project_name = SupplierLocaltourItemModel::where('id', $item['index_id'])->value('name', '');
                    break;
            }
            $supp_name = $supplier_name ? strpos($schedule[$item['days']][$type], $supplier_name) === false ? $supplier_name . ' ' : '' : '';
            $pack_name = $package_name ? strpos($schedule[$item['days']][$type], $package_name) === false ? $package_name . ' ' : '' : '';
            if ($item['type'] == 'scenic_spot_item') {
                $name = $type_name . $pack_name . ' ' . $project_name;
                $schedule[$item['days']][$type] .= str_replace('\r\n', '', str_replace(':', ':\r\n', $name)) . "\r\n";
            } else if (in_array($item['type'], ['breakfast', 'lunch', 'afternoon_tea', 'afternoon_snack', 'dinner', 'night_snack'])) {
                $schedule[$item['days']][$type] .= $type_name . $project_name . "\r\n";
            } else {
//                $schedule[$item['days']][$type] .= $type_name . $supp_name . $pack_name . $project_name . "\r\n    ";
                $schedule[$item['days']][$type] .= $type_name . $supp_name . "\r\n";
            }
            $item['supplier']['packages'] = $item['supplier']['package'];
            $item['packages'] = $item['package'];
            if ($type == 'local_tour') {
                $rows = SupplierLocaltourRouteModel::where('supplier_localtour_item_id', $item['index_id'])->select()->toArray();
                foreach ($rows as $kk => $vv) {
                    $d = $kk + 1;
                    $schedule[$d][$type] = $vv['content'];
                }
            }
        }

        //暫時不顯示交通方式
        //fee_type,1-按車型,0-按套餐
        //car_type,套餐分類,'normal' => '當地旅遊車','hzmb' => '港珠澳直通車','throughcar' => '直通車'
        // traffic_type，運輸工具，0-航空，1-高鐵，2-動車，3-火車，4-輪船，5-汽車
        /*$traffic = [];
        foreach ($detail['traffic'] as $key => $v) {
            $type = $v['traffic_type'];
            $day = $v['start_day'];
            $key = substr_count($traffic[$day], "\r\n") + 1;
            if ($type == 0) {
                $type_name_tmp = $key . '、(飛機):';
                $routes = SupplierAirlineRouteModel::where('id', $v['route'])->field('departure_airport_id,destination_airport_id')->find()->toArray();
                $names = AirportModel::where('id', 'in', array_values($routes))->select()->toArray();
                $traffic[$day] .= $type_name_tmp . $names[0]['name'] . '->' . $names[1]['name'];
            } elseif (in_array($type, [1, 2, 3])) {
                switch ($type) {
                    case 1:
                        $type_name_tmp = $key . '、(高鐵):';
                        break;
                    case 2:
                        $type_name_tmp = $key . '、(動車):';
                        break;
                    case 3:
                        $type_name_tmp = $key . '、(火车):';
                        break;
                    default:
                        $type_name_tmp = $key . ':';
                }
                $routes = RailwayRouteModel::where('id', $v['route'])->field('railway_station_id,arrival_railway_station_id')->find()->toArray();
                $names = RailwayStationModel::where('id', 'in', array_values($routes))->select()->toArray();
                $traffic[$day] .= $type_name_tmp . $names[0]['name'] . '->' . $names[1]['name'];
            } elseif ($type == 4) {
                $type_name_tmp = $key . '、(輪船):';
                $traffic[$day] .= $type_name_tmp . SupplierShipRouteModel::where('id', $v['route'])->value('name', '');;
            } elseif ($type == 5) {
                $type_name_tmp = $key . '、(汽車):';
                if ($v['fee_type'] == 1) {
                    $traffic[$day] .= $type_name_tmp . '大巴直達';
                } else {
                    $traffic[$day] .= $type_name_tmp . ($v['car_type'] == 'normal' ? '當地旅遊車' : ($v['car_type'] == 'hzmb' ? '港珠澳直通車' : '直通車'));
                }
            }
            $traffic[$day] .= "\r\n";
        }*/
        $tmp = [];
        foreach ($schedule as $key => $value) {
            $valueA = $this->resort($value);
            foreach ($valueA as $k => $v) {
                $tmp[$key][] = [
                    'days' => $key,
                    'type' => $k,
                    'content' => is_string($v) ? rtrim(preg_replace('/\((.+?)\)/', '', $v), "\r\n") : $v
                ];
            }
        }
        /*foreach ($traffic as $kk => $vv) {
            $tmp[$kk][] = [
                'days' => $kk,
                'type' => 'traffic',
                'content' => "交通方式:\r\n" . rtrim($vv, "\r\n")
            ];
        }*/
        return array_values($tmp);
    }

    /**
     * 餐飲 > 景點 > 酒店
     * @param $arr
     * @return array
     */
    private function resort($arr)
    {
        $ret = [];
        $sort = ['repast', 'scenic_spot_item', 'hotel', 'local_tour', 'traffic', 'car_company', 'ship_company'];
        foreach ($sort as $k) {
            foreach ($arr as $key => $item) {
                if ($key == $k) {
                    $ret[$k] = $item;
                    unset($arr[$k]);
                }
            }
        }
        return $ret;
    }

    public function getBaseList($product_id)
    {
        $pid = ProductBaseModel::where('product_id', $product_id)->value('pid');
        $where = [
            ['b.pid', '=', $pid],
            ['b.status', '=', 1],
            ['b.is_del', '=', 0],
            ['b.company_id', '=', $this->company_id],
            ['s.status', '=', 1],
            ['s.is_del', '=', 0],
            ['s.series_type', '=', 0],
            ['s.company_id', '=', $this->company_id],
        ];
        $baseInfo = ProductBaseModel::alias('b')
            ->leftjoin(full_table_name(new ProductSeriesModel()) . ' s', 's.series_id=b.series_id')
            ->where($where)
            ->field('b.currency,b.product_id,b.product_id,b.customer_num,b.customer_min')
            ->select()
            ->toArray();
        if ($baseInfo == false) {
            $this->response->errorNotFound();
        }
        return $baseInfo;
    }

    /**
     * 獲取總房費
     * @return array|\think\response\Json
     */
    public function getRoomFee()
    {
        $request = $this->request;
        $post = $request->post();
        return $this->apiResponse(function () use ($post) {
            return $this->service->getRoomFee($post);
        });
    }
}