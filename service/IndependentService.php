<?php
/**
 * Description : 自由行首页 产品详情 搜索等服务
 * Author      : Kobin
 * CreateTime  : 2021/8/3 上午11:32
 */

namespace app\api\service;


use app\admin\service\PriceService;
use app\admin\service\StockService;
use app\api\job\MemberTraceJob;
use app\api\lib\BizException;
use app\api\model\app\BannerModel;
use app\api\model\independent\IndependentCategory;
use app\api\model\independent\IndependentCombination;
use app\api\model\independent\IndependentCombinationDetail;
use app\api\model\independent\IndependentProvision;
use app\api\model\independent\IndependentRegion;
use app\api\model\independent\IndependentRegionHot;
use app\api\model\member\MemberCollection;
use app\api\model\member\MemberProductTrace;
use app\api\model\member\MemberSearchModel;
use app\api\model\product\ProductCateModel;
use app\facade\Redis;
use comm\constant\CK;
use comm\constant\CN;
use comm\model\independent\IndependentCombinationRegions;
use comm\model\supplier\SupplierCarRoute;
use comm\model\supplier\SupplierSpecialDayFee;
use comm\model\system\SystemConfig;
use think\Exception;
use think\Model;

class IndependentService extends ApiServiceBase
{
    const ORDER_BY = [
        1 => 'ctime desc',
        2 => 'single desc',
        3 => 'single asc',
        // TODO
        4 => 'ctime desc',
        5 => 'ctime desc'
    ];
    const WEEK = array('周日', '周一', '周二', '周三', '周四', '周五', '周六');

    /**
     * @return array
     * @throws Exception
     */
    public function index()
    {
        try {
            $banner = $this->getIndependentBanner();
            $category = $this->getCategory();
            $hot_destination = $this->getHotDestination();
            $suggestion = $this->getSuggestion();
            return compact('banner', 'category', 'hot_destination', 'suggestion');
        } catch (\Exception $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    /**
     * @param $id
     * @param $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \Exception
     */
    public function detail($id, $uid): array
    {
        try {
            $today = date('Y-m-d');
            $model = new IndependentCombination();
            $info = $model->field(IndependentCombination::FIELDS)->where(['id' => $id])
                ->with(['album', 'provision', 'a4files', 'content', 'detail'])
                ->findOrEmpty()->toArray();
            if (!empty($info)) {
                // 异步处理访问记录
                queue(MemberTraceJob::class, [
                    'member_id' => $uid ?: 0,
                    'productable' => MemberProductTrace::TYPE_INDEPENDENT,
                    'product' => $id
                ]);
                $info['picture'] .= env('ALIYUN_OSS.appResizem');
                $info['calendar_month'] = [];
                $info['is_collected'] = MemberCollection::isCollected($uid, MemberCollection::TYPE_INDEPENDENT, $id);
                $info['single_price'] = $info['single'] = (int)($info['single'] / 100);
                foreach ($info['provision'] as $k => $p) {
                    $info['provision'][$k] = IndependentProvision::getById($p['provision_id']);
                }
                foreach ($info['album'] as $k => &$p) {
                    $p['photo'] .= env('ALIYUN_OSS.appResizem', '');;
                }
                $start = $info['valid_start'] > $today ? $info['valid_start'] : $today;
                $info['price'] = $this->getPriceList(
                    $start, $info['valid_end'], $info['currency_id'], $info['calendar_month'], 0, 0, $info
                );
                $content = '';
                $exchange_rate = SystemConfig::getExchangeRate('hk_currency');

                if (!empty($info['content'])) {
                    foreach ($info['content'] as $con) {
                        switch ($con['type']) {
                            case'text':
                                $content .= '<p>' . $con['text'] . '</p>';
                                break;
                            case'photo':
                                $content .= "<p><img src='" . $con['text'] . "'/></p>";
                                break;
                            case'youtube':
                                $content .= "<p><a href='" . $con['text'] . "'>" . 'YOUTUBE視訊' . " </a ></p>";
                                break;
                            case'url':
                                $content .= "<p><a href=" . $con['text'] . "'>" . $con['title'] . " </a ></p>";
                                break;
                            default:
                                if (!empty($con['text'])) {
                                    $content .= '<h5>' . $con['text'] . '</h5>';
                                }
                                break;
                        }
                    }
                }
                $info['content'] = $content;
                if ($info['discount'] && $info['discount_end'] && $info['discount_end'] < date('Y-m-d H:i:s')) {
                    $info['discount'] = 0;
                }
                if (!empty($info['detail'])) {
                    foreach ($info['detail'] as &$detail) {
                        $this->handleDetailSnapshot($exchange_rate, $info, $detail);
                    }
                }
            } else {
                BizException::throwException(33001);
            }

            $suggestion = $this->getSuggestion(8);
            return compact('info', 'suggestion');
        } catch (\Exception $e) {
            dd($e->getFile() . $e->getLine() . $e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * @param $exchange_rate
     * @param $info
     * @param $detail
     * @param string $date
     */
    public function handleDetailSnapshot($exchange_rate, $info, &$detail, $date = '')
    {
        if (!empty($detail['snapshot'])) {
            $detail['items'] = [];
            $snap = json_decode($detail['snapshot'], true);
            $rate = (string)$exchange_rate[$detail['currency_id']][$info['currency_id']];
            $currency = config('system.currency');
            $priceService = PriceService::getInstance();

            switch ($detail['type']) {
                case CN::TYPE_HOTEL:
                    foreach ($snap as $ss) {
                        $stock = 0;
                        $rooms = [];
                        if (isset($ss['item'])) {
                            foreach ($ss['item'] as $p) {
                                if ($p['type'] == 5) {
                                    array_push($rooms, $p['index_id']);
                                }
                            }
                        }

                        if ($date != '') {
                            $stockService = StockService::getInstance();
                            $stockArr = [];
                            if (!empty($rooms)) {
                                foreach ($rooms as $room) {
                                    array_push($stockArr, $stockService->getQty($room, StockService::FIELD_QTY_INDP, $date));
                                }
                            }
                            $stock = min($stockArr);

                            $price = $priceService->getPrice(SupplierSpecialDayFee::TYPE_PACKAGE, (int)$ss['id'], [$date]);
                            $priceFinally = $price['data'][$date]['dispersion_room_fee'];
                        } else {
                            $priceFinally = isset($ss['dispersion_fee']) ? $ss['dispersion_fee'] : 0;
                        }
                        if (!isset($detail['name']) && isset($ss['supplierSchedule'])) {
                            $detail['name'] = $ss['supplierSchedule']['name'];
                        }
                        if (isset($ss['id']) && isset($ss['name']) && isset($ss['useqty'])) {
                            $detail['items'][] = [
                                'item_id' => $ss['id'],
                                'item_name' => $ss['name'],
                                'useqty' => $ss['useqty'],
                                'symbol' => $currency[$info['currency_id']]['symbol'],
                                'price' => (int)bcmul((string)$priceFinally, (string)$rate, 0),
                                'price_single' => (int)bcmul((string)bcdiv((string)$priceFinally, (string)$ss['useqty'], 0), (string)$rate, 0),
                                'price_child' => 0,
                                'price_child_single' => 0,
                                'qty' => $stock,
                            ];
                        }

                    }
                    break;
                case CN::TYPE_SCENE:
                    if (!isset($detail['name']) && isset($snap['supplierSchedule'])) {
                        $detail['name'] = $snap['supplierSchedule']['name'];
                    }
                    if ($date != '') {
                        $price = $priceService->getPrice(SupplierSpecialDayFee::TYPE_SCENIC, (int)$snap['id'], [$date]);
                        $priceAdultFinally = $price['data'][$date]['dispersion_adult_fee'];
                        $priceChildFinally = $price['data'][$date]['dispersion_child_fee'];
                    } else {
                        $priceAdultFinally = isset($snap['dispersion_adult_fee']) ? $snap['dispersion_adult_fee'] : 0;
                        $priceChildFinally = isset($snap['dispersion_child_fee']) ? $snap['dispersion_child_fee'] : 0;
                    }
                    if (isset($snap['id']) && isset($snap['name'])) {
                        $detail['items'][] = [
                            'item_id' => $snap['id'],
                            'item_name' => $snap['name'],
                            'useqty' => 1,
                            'symbol' => $currency[$info['currency_id']]['symbol'],
                            'price' => (int)bcmul((string)$priceAdultFinally, (string)$rate, 0),
                            'price_single' => (int)bcmul((string)$priceAdultFinally, (string)$rate, 0),
                            'price_child' => (int)bcmul((string)$priceChildFinally, (string)$rate, 0),
                            'price_child_single' => (int)bcmul((string)$priceChildFinally, (string)$rate, 0),
                            'qty' => 100,
                        ];
                    }
                    break;
                case CN::TYPE_CAR:
                    if (!isset($detail['name']) && isset($snap['supplierTraffic'])) {
                        $detail['name'] = $snap['supplierTraffic']['name'];
                    }
                    if ($date != '') {
                        $fieldPrefix = in_array(date('w', strtotime($date)), [6, 7]) ? 'dispersion_weekend_price' : 'dispersion_weekday_price';
                    } else {
                        $fieldPrefix = 'dispersion_weekday_price';
                    }
                    if (isset($snap['car_route_id'])) {
                        $route = SupplierCarRoute::where(['id' => $snap['car_route_id']])->findOrEmpty()->toArray();
                        $name = $route['name_tc'];
                    } else {
                        $name = '';
                    }
                    if (isset($snap['id']) && isset($snap[$fieldPrefix])) {
                        $detail['items'][] = [
                            'item_id' => $snap['id'],
                            'item_name' => $name,
                            'useqty' => 1,
                            'symbol' => $currency[$info['currency_id']]['symbol'],
                            'price' => (int)bcmul((string)$snap[$fieldPrefix], (string)$rate, 0),
                            'price_single' => (int)bcmul((string)$snap[$fieldPrefix], (string)$rate, 0),
                            'price_child' => (int)bcmul((string)$snap[$fieldPrefix], (string)$rate, 0),
                            'price_child_single' => (int)bcmul((string)$snap[$fieldPrefix], (string)$rate, 0),
                            'qty' => 100,
                        ];
                    }
                    break;
                case CN::TYPE_AIR:
                    if (!isset($detail['name']) && isset($snap['supplierTraffic'])) {
                        $detail['name'] = $snap['supplierTraffic']['name'];
                    }
                    if (isset($snap['id']) && isset($snap['name']) && isset($snap['price'])) {
                        $detail['items'][] = [
                            'item_id' => $snap['id'],
                            'item_name' => $snap['name'],
                            'useqty' => 1,
                            'symbol' => $currency[$info['currency_id']]['symbol'],
                            'price' => (int)bcmul((string)$snap['price'], (string)$rate, 0),
                            'price_single' => (int)bcmul((string)$snap['price'], (string)$rate, 0),
                            'price_child' => (int)bcmul((string)$snap['price'], (string)$rate, 0),
                            'price_child_single' => (int)bcmul((string)$snap['price'], (string)$rate, 0),
                            'qty' => 100,
                        ];
                    }
                    break;
            }
            unset($detail['snapshot']);
        }

    }

    /**
     * 获取某自由行套票某选项子选项价格
     * @param $params
     * @return array
     */
    public function price($params)
    {
        $info = (new IndependentCombination())
            ->field(IndependentCombination::FIELDS)->where(['id' => $params['product_id']])->findOrEmpty()->toArray();
        if (empty($info)) {
            BizException::throwException(33001);
        }
        $exchange_rate = SystemConfig::getExchangeRate('hk_currency');
        $detail = IndependentCombinationDetail::where(['id' => $params['detail_id']])->findOrEmpty()->toArray();
        $this->handleDetailSnapshot($exchange_rate, $info, $detail, $params['date']);

        return $detail;
    }

    /**
     * @param $id
     * @param $params
     * @return array
     * @throws \Exception
     */
    public function productPriceCalendar($id, $params)
    {
        $today = date('Y-m-d');
        $info = (new IndependentCombination())
            ->with(['detail'])
            ->field(IndependentCombination::FIELDS)->where(['id' => $id])
            ->findOrEmpty()->toArray();
        if (!empty($info)) {
            $start = $info['valid_start'] > $today ? $info['valid_start'] : $today;
            $info['calendar_month'] = [];
            $ret = $this->getPriceList(
                $start, $info['valid_end'], $info['currency_id'], $info['calendar_month'],
                $params['year'], $params['month'], $info
            );
        } else {
            $ret = [];
        }
        return $ret;
    }

    /**
     * 获取价格日历  year和month则是获取某年某月的报价
     * @param $start_date
     * @param $end_date
     * @param $currency_id
     * @param $sorted
     * @param int $year
     * @param int $month
     * @param array $info
     * @return array
     * @throws \Exception
     */
    private function getPriceList($start_date, $end_date, $currency_id, &$sorted, int $year, int $month, array $info): array
    {
        try {
            $all_price = [];
            $end_date_stamp = strtotime($end_date);
            $days = ($end_date_stamp - strtotime($start_date)) / 86400 + 1;
            for ($d = 0; $d < $days; $d++) {
                $day_stamp = strtotime('+' . $d . 'days', strtotime($start_date));
                $day = date('Y-m-d', $day_stamp);
                if ($year && $month) {
                    if (date('Y', $day_stamp) == $year && date('m', $day_stamp) == $month) {
                        $this->setCalendarDefault($all_price, $day, $day_stamp, $info['symbol']);
                    }
                } else {
                    $this->setCalendarDefault($all_price, $day, $day_stamp, $info['symbol']);
                }
            }

//            $details = IndependentCombinationDetail::where('independent_combination_id', $product_id)->select()->toArray();
            $priceService = PriceService::getInstance();

            foreach ($info['detail'] as $key => $detail) {
                if (!is_numeric($detail['item_id'])) {
                    $detail['item_id'] = json_decode($detail['item_id'])[0];
                }
                switch ($detail['type']) {
                    case CN::TYPE_HOTEL:
                        $item_id = is_numeric($detail['item_id']) ? $detail['item_id']: json_decode($detail['item_id'],true)[0];
                        $details[$key]['price'] = $priceService->getPrice(
                            SupplierSpecialDayFee::TYPE_PACKAGE,
                            (int)$item_id,
                            array_column($all_price, 'date')
                        );
                        break;
                    case CN::TYPE_SCENE:
                        $details[$key]['price'] = $priceService->getPrice(
                            SupplierSpecialDayFee::TYPE_SCENIC,
                            (int)$detail['item_id'],
                            array_column($all_price, 'date')
                        );
                        break;
                    default:
                        break;
                }
            }
            $this->getAllPrice($all_price, $details, $currency_id);

            if ($year == 0 && $month == 0) {
                //详情只获取 10天报价
                $price = array_slice($all_price, 0, 10);
                // 获取月份价格统计
                $this->getMonthMin($all_price, $sorted);
//                $sorted = $this->setPriceSymbol($sorted, $currency_id,'min');
            } else {
                // 有年月
                $price = $all_price;
            }
//            $price = $this->setPriceSymbol($price, $currency_id,'price');

            return $price;
        } catch (\Exception $exception) {
            throw new \Exception($exception->getLine() . $exception->getMessage());
        }
    }

    /**
     * @param array $data
     * @param int $currency_id
     * @param string $field
     * @return array
     */
    private function setPriceSymbol($data = [], $currency_id = 2, $field = 'price')
    {
        $ret = [];
        $currency = config('system.currency');
        $ret = array_map(
            function ($item) use ($currency, $field, $currency_id) {
                $item[$field] = $currency[$currency_id]['symbol'] . $item[$field];
                return $item;
            }, $data);
        return $ret;
    }

    /**
     * @param $all_price
     * @param $day
     * @param $day_stamp
     */
    private function setCalendarDefault(&$all_price, $day, $day_stamp, $symbol)
    {
        $all_price[] = [
            'date' => $day,
            'year' => intval(date('Y', $day_stamp)),
            'month' => intval(date('m', $day_stamp)),
            'day' => intval(date('d', $day_stamp)),
            'week' => self::WEEK[date('w', $day_stamp)],
            'symbol' => $symbol,
            'price' => 0,
            'qty' => 100,
        ];
    }

    /**
     * @param $all_price
     * @param $details
     * @param $currency
     */
    private function getAllPrice(&$all_price, $details, $currency)
    {
        $exchangeRate = SystemConfig::getExchangeRate('hk_currency');
        foreach ($all_price as $d => $item) {
            foreach ($details as $key => $detail) {
                if ($detail['type'] == CN::TYPE_HOTEL) {
                    $all_price[$d]['price'] = (float)bcadd(
                        (string)$all_price[$d]['price'],
                        bcmul(
                            (string)bcmul((string)$detail['price']['data'][$item['date']]['dispersion_room_fee'], $detail['qty']),
                            $exchangeRate[$detail['currency_id']][$currency]
                        )
                    );
                } else if ($detail['type'] == CN::TYPE_SCENE) {
                    $all_price[$d]['price'] = (float)bcadd(
                        (string)$all_price[$d]['price'],
                        bcmul(
                            (string)bcmul((string)$detail['price']['data'][$item['date']]['dispersion_adult_fee'], $detail['qty']),
                            $exchangeRate[$detail['currency_id']][$currency]
                        )
                    );
                } else {
                    $all_price[$d]['price'] = (float)bcadd(
                        (string)$all_price[$d]['price'],
                        bcmul(
                            bcdiv((string)$detail['amount'], (string)100),
                            $exchangeRate[$detail['currency_id']][$currency]
                        )
                    );
                }

            }
        }
    }

    /**
     * 获取最低ß
     * @param $all_price
     * @param $sorted
     */
    private function getMonthMin($all_price, &$sorted)
    {
        $ret = [];
        foreach ($all_price as $d => $item) {
            if (!isset($sorted[$item['year'] . '-' . $item['month']])) {
                $sorted[$item['year'] . '-' . $item['month']] = [];
            }
            array_push($sorted[$item['year'] . '-' . $item['month']], $item['price']);
        }
        if (!empty($sorted)) {
            foreach ($sorted as $ym => $month) {
                $y_m = explode('-', $ym);
                $ret[] = [
                    'year' => (int)$y_m[0],
                    'month' => (int)$y_m[1],
                    'min' => min($month)
                ];
            }
        }
        $sorted = $ret;
    }

    /**
     * 搜索前置：索索历史记录  热门地区  自由行地区分类
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function beforeSearch()
    {
        if ($this->member_id) {
            $history = MemberSearchModel::getMemberSearchRecords(
                MemberSearchModel::TYPE_INDEPENDENT,
                $this->company_id,
                $this->member_id,
                10
            );
        } else {
            $history = [];
        }
        $hotRegion = IndependentRegionHot::getLabelValue($this->company_id);
        $regions = IndependentRegion::getLabelValue($this->company_id);
        $category = IndependentCategory::getLabelValue($this->company_id);
        return compact('history', 'hotRegion', 'regions', 'category');
    }

    /**
     * @param $params
     * @param $uid
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function search($params, $uid): array
    {
        $params['order'] = $params['order'] ?: 1;
        $currency = config('system.currency');
        $model = (new IndependentCombination())
            ->alias('p')
            ->field('p.*')
            ->where('is_del', 0)
            ->where('is_approved', 1)
            ->where('company_id', $this->company_id)
            ->where('status', 1)
//            ->where('effective_start', '<=', date('Y-m-d'))
            ->where('effective_end', '>=', date('Y-m-d'))
            ->order(self::ORDER_BY[$params['order']]);
        $model = $this->setSearchCondition($model, $params);
        return $model->paginate(['list_rows' => $params['limit'], 'page' => $params['page']])
            ->map(function ($item) use ($uid, $currency) {
                $item['is_collected'] = $uid ? MemberCollection::isCollected($uid, MemberCollection::TYPE_INDEPENDENT, $item['id']) : 0;
                $item['symbol'] = $currency[$item['currency_id']]['symbol'];
                $item['sales_volume'] = 0;
                $item->picture .= env('ALIYUN_OSS.appResizem');
                return $item;
            })
            ->toArray();
    }

    /**
     * 删除搜索记录
     * @param $params
     * @param $memberId
     * @return mixed
     */
    public function deleteHistory($params, $memberId): array
    {
        $type = $params['type'] ?? 2;
        MemberSearchModel::where('member_id', $memberId)->where('type', $type)->delete();
        return [true];
    }

    /**
     * @param IndependentCombination $model
     * @param array $params
     * @return mixed
     * @throws \Exception
     */
    private function setSearchCondition($model, $params)
    {
        // 关键词搜索
        if (isset($params['words']) && $params['words'] != '') {
            $model = $model->where('p.name_tc', 'like', "%$params[words]%");
        }
        // 筛选天数
        if (isset($params['days']) && !empty($params['days'])) {
            $days = array_unique(array_filter($params['days']));
            if (!empty($days)) {
                $whereOr = [];
                foreach ($days as $day) {
                    if ($day == 13) {
                        $whereOr[] = ['p.days', '>', 12];
                    } else if ($day == 80) {
                        $whereOr[] = ['p.days', '>', 8];
                    } else {
                        if (in_array($day, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])) {
                            $whereOr[] = ['p.days', '=', $day];
                        }
                    }
                }
                $model = $model->where(
                    fn($query) => $query->whereOr($whereOr)
                );
            }
        }

        // 出游日期筛选
        if (isset($params['from']) && $params['from'] != '') {
            $model = $model->where('p.valid_start', '<=', $params['from']);
        }
        if (isset($params['to']) && $params['to'] != '') {
            $model = $model->where('p.valid_end', '>=', $params['to']);
        }
        // 价格筛选
        if (isset($params['price_from']) && $params['price_from'] != '') {
            $model = $model->where('p.sale_amount', '>=', $params['price_from'] * 100);
        }
        if (isset($params['price_to']) && $params['price_to'] != '') {
            $model = $model->where('p.sale_amount', '<=', $params['price_to'] * 100);
        }
        // 地区筛选
        if (isset($params['region']) && $params['region'] != '') {
            $model = $model->where('p.region', 'like', "%{$params['region']}%");
        }
        // 分类
        if (isset($params['category']) && $params['category'] != '') {
            $model = $model->where('p.cate_id', 'in', $this->getCategoryArray($params['category']));
        }
        return $model;
    }

    /**
     * @param int $catId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getCategoryArray(int $catId): array
    {
        $key = CK::API_IND_CATS . ':' . $this->company_id;
        if (!Redis::exists($key)) {
            $data = IndependentCategory::field(['id', 'pid', 'name'])
                ->where([
                    'status' => 1, 'is_del' => 0, 'is_approved' => 1, 'company_id' => $this->company_id
                ])
                ->select()->toArray();
            Redis::set($key, json_encode(array_column($data, null, 'id')));
            Redis::expire(CK::API_IND_CATS, CN::ONE_HOUR);
        }
        $data = json_decode(Redis::get($key), true);
        $ret = $this->getTree($data);
        $target = [];
        $this->getTarget($ret, $catId, $target);
        $ret = !empty($target) ? $this->recur('id', $target) : [$catId];
        //一级分类问题
        return $ret;
    }

    /**
     * @param $source
     * @param $target_id
     * @param $ret
     */
    private function getTarget($source, $target_id, &$ret)
    {
        foreach ($source as $item) {
            if ($item['id'] == $target_id) {
                $ret = $item;
                break;
            } else if (isset($item['child'])) {
                $this->getTarget($item['child'], $target_id, $ret);
            }
        }
    }

    /**
     * 使用引用获取无限极分类的组织
     * @param $data
     * @return array
     */
    function getTree($data): array
    {

        $items = [];
        // 构建一个新的数组 新数组的key值是自己的主键id值(我这里表的主键是cat_id)
        // 为何要构建这样一个数组 这就是和下面第二个foreach有关了,看了代码后就会明白何为巧妙引用了
        foreach ($data as $v) {
            $items[$v['id']] = $v;
        }
//        dd($data,$target,$items);
        $tree = [];
        // 将数据进行无限极分类
        foreach ($items as $key => $val) {
            if (isset($items[$val['pid']])) {
                //关键是看这个判断,是顶级分类就给$tree,不是的话继续拼凑子分类（结合上述&用法）
                $items[$val['pid']] ['child'] [] = &$items[$key];
            } else {
                $tree[$key] = &$items[$key];
            }
        }
        // 返回无限极分类后的数据
        return $tree;
    }

    /**
     * 获取多维数组的某个key的所有value
     * @param $key
     * @param $array
     * @return array
     */
    private function recur($key, $array): array
    {
        $data = [];
        array_walk_recursive($array, function ($v, $k) use ($key, &$data) {
            if ($k == $key) {
                array_push($data, $v);
            }
        });
        return $data;
    }


    /**
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getIndependentBanner()
    {
        $ret = BannerModel::where('company_id', $this->company_id)
            ->where('module', BannerModel::MODULE_INDEPENDENT)
            ->where('location', 'in', [2, 3])
            ->order('sort', 'desc')
            ->select()
            ->map(function (Model $item) {
                $item->picture .= env('ALIYUN_OSS.appResizel', '');
                return $item;
            })
            ->toArray();
        return $ret;
    }

    /**
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getCategory(): array
    {
        $ret = IndependentCategory::field(['id', 'pid', 'name', 'image'])
            ->where([
                'company_id' => $this->company_id,
                'status' => CN::STATUS_ON,
                'is_approved' => 1,
                'is_del' => CN::DEL_NO,
                'pid' => 0
            ])
            ->order('sort', 'desc')
            ->select()
            ->map(function (Model $item) {
                $item->image .= env('ALIYUN_OSS.appResizes', '');
                return $item;
            })
            ->toArray();
        return $ret;
    }

    /**
     * @return mixed
     */
    private function getHotDestination()
    {
        $ret = IndependentRegionHot::where('company_id', $this->company_id)->alias('hr')
            ->leftJoin(full_table_name(new ProductCateModel()) . ' c', 'c.cate_code = hr.cat')
            ->field(['hr.id', 'hr.cat', 'c.cate_name', 'c.cate_img'])
            ->where('is_del', 0)
            ->order('sort', 'desc')
            ->select()->toArray();
        return $ret;
    }

    /**
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getSuggestion($limit = 10)
    {
        $ret = IndependentCombination::where('company_id', $this->company_id)
            ->where('is_del', 0)
            ->where('status', 1)
            ->where('is_approved', 1)
            ->where('effective_start', '<=', date('Y-m-d'))
            ->where('effective_end', '>=', date('Y-m-d'))
            ->field(IndependentCombination::FIELDS)
            ->order('sort', 'desc')
            ->limit($limit)
            ->select()
            ->map(function (Model $item) {
                $item->picture .= env('ALIYUN_OSS.appResizes', '');
                return $item;
            })
            ->toArray();
        return $ret;
    }
}