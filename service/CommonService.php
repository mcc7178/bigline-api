<?php


namespace app\api\service;


use app\admin\service\PriceService;
use app\admin\service\StockService;
use comm\model\branch\Branch;
use comm\model\product\ProductRegister as ProductRegisterModel;
use comm\model\supplier\SupplierSpecialDayFee;
use comm\model\system\AppVersionModel;
use comm\model\system\CompanyOffLinePayModel;
use comm\service\PackageRoomStock;
use think\Model;

class CommonService extends ApiServiceBase
{
    /**
     * @param $company_id
     * @return array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function getBranches($company_id)
    {
        $area = config('system.area');
        $ret = [
            'hong_kong' => [
                'name' => '香港',
                'district' => [],
            ],
            'mainland' => [
                'name' => '大陸',
                'district' => [],
            ],
            'tel_center' => [
                'name' => '電話中心',
                'district' => [],
            ],
        ];
        $date = Branch::where('company_id', $company_id)
            ->where('type', 'in', ['branch', 'tel_center'])
            ->where('status', 1)
            ->field([
                'location_type', 'region_type', 'name', 'shortname', 'is_self_owned', 'start_open_time',
                'end_open_time', 'lng', 'lat', 'address', 'fax_no', 'tel_no', 'business_status', 'type'
            ])
            ->select()
            ->toArray();
        foreach ($date as $item) {
            $tel = $item['tel_no'];
            $item['tel_no'] = [];
            $item['start_open_time'] = date('H:i', strtotime($item['start_open_time']));
            $item['end_open_time'] = date('H:i', strtotime($item['end_open_time']));
            foreach ($tel as $t) {
                array_push($item['tel_no'], $t['tel_no']);
            }
            if ($item['type'] == 'branch') {
                self::setValues($item, $area, $ret);
            } else {
                array_push($ret['tel_center']['district'], $item);
            }

        }
        return $ret;
    }

    private static function setValues($item, $area, &$data)
    {
        $item['district_name'] = $area[$item['location_type']]['children'][$item['region_type']];
        array_push($data[$item['location_type']]['district'], $item);
    }

    /**
     * @param $company_id
     * @param $device_id
     * @param $version
     * @return mixed|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function getLastVersion($company_id, $device_id, $version = '')
    {

        $model = (new AppVersionModel())->where(['company_id' => $company_id, 'type' => $device_id]);
        if ($version) {
            $version = str_replace('.', '', str_replace('V', '', $version));
            $model = $model->where('version_id', '>', $version);
        }
        $r = $model->order('version_id desc')
            ->limit(1)
            ->select()
            ->toArray();
        if (!empty($r) && count($r) >= 1) {
            return $r[0];
        } else {
            return null;
        }
    }

    /**
     * @param $company_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function paymentType($company_id): array
    {
        return CompanyOffLinePayModel::where('status', 1)->where('company_id', $company_id)->select()->toArray();
    }

    /**
     * TODO
     * @param $product_id
     * @return string
     */
    public static function getTermsGroup($product_id)
    {

        return '';
    }

    /**
     * TODO
     * @return string
     */
    public static function getTermsIndependent()
    {

        return '';
    }

    /**
     * 获取酒店基礎數據和升級房數據
     * @param \think\Model $model
     * @param string $tour_date
     * @param string $currency
     * @return array
     */
    public static function getUpgradePackage(Model $model, string $tour_date, string $currency): array
    {
        $main_package = [];
        $upgradeable_package = [];

        foreach ($model['schedule'] as $key => $schedule) {
            $days_value = "第{$schedule->days}天";
            $group_number = $schedule->group_number;
            if (empty($schedule['package']) || $schedule['type'] != 'hotel') {
                continue;
            }

            if (array_key_exists($group_number, $main_package)) {
                $main_package[$group_number]['days_value'] .= '+' . $days_value;
                $main_package[$group_number]['days'][] = $schedule->days;
                continue;
            }

            $add_day = $schedule->days - 1;
            $date = date('Y-m-d', strtotime("+$add_day day", $tour_date));
            $price = call_user_func([PriceService::getInstance(), 'getPrice'], SupplierSpecialDayFee::TYPE_PACKAGE, (int)$schedule['package']['id'], [$date]);
            $schedule->package->fee = $price['data'][$date]['group_fee'];

            // 计算套餐的费用
            $main_package[$group_number] = ProductRegisterModel::setServiceFee($schedule->package, $schedule['supplier']['default_currency_id'], $currency);
            $main_package[$group_number]['supplier_name'] = $schedule->supplier->name;
            $main_package[$group_number]['days_value'] = $days_value;
            $main_package[$group_number]['group_number'] = $schedule->days;
            $main_package[$group_number]['days'][] = $schedule->days;
            $main_package[$group_number]['fee'] = $schedule->package->fee;

            $room = explode('+', $main_package[$group_number]['room_name']);
            $main_package[$group_number]['room_name'] = $room[0];

            if (!isset($main_package[$group_number]['start_day']) || $main_package[$group_number]['start_day'] > $schedule->days) {
                $main_package[$group_number]['start_day'] = $schedule->days;
            }
            $main_package[$group_number]['room_qty'] = PackageRoomStock::get($date, $schedule['package']['id'], StockService::FIELD_QTY_GROP);
        }

        foreach ($model['schedule'] as $key => $schedule) {
            $group_number = $schedule->group_number;
            if (empty($schedule['package']) || $schedule['type'] != 'hotel') {
                continue;
            }
            $schedule_format_item = array_filter($schedule['package']['item']->toArray(), static fn($item) => $item['type'] !== 5);
            $schedule_format_item = array_map(static fn($item) => $item['type'] . '-' . $item['index_id'], $schedule_format_item);

            // 查询同一供应商套餐天数一样的其它套餐 如果附加项目是一样的 就可以升级
            foreach ($schedule['supplier']['package'] as $package) {
                if (
                    $package['id'] === $schedule['package']['id']
                    || $package['days'] !== $schedule['package']['days']
                    || in_array($package['sales_type'], [2, 4, 6], true)
                    || count($package['item']) !== count($schedule['package']['item'])
                ) {
                    continue;
                }

                // 将两个套餐的附加项目拿出来比较
                $item = array_filter($package['item']->toArray(), static fn($item) => $item['type'] !== 5);
                $self_item = array_map(static fn($item) => $item['type'] . '-' . $item['index_id'], $item);
                if (!array_diff($self_item, $schedule_format_item)) {
                    $price = call_user_func([PriceService::getInstance(), 'getPrice'], SupplierSpecialDayFee::TYPE_PACKAGE, (int)$package['id'], [$date]);
                    $package->fee = $price['data'][$date]['group_fee'];
                    $upgradeable_package[$schedule->days][$package['id']] = ProductRegisterModel::setServiceFee($package, $schedule['supplier']['default_currency_id'], $model['currency'], $main_package[$group_number]);
                    $upgradeable_package[$schedule->days][$package['id']]['room_qty'] = PackageRoomStock::get($date, $package['id'], StockService::FIELD_QTY_GROP);
                    $upgradeable_package[$schedule->days][$package['id']]['start_day'] = $schedule->days;
                }
            }
        }
        $upgrade = [];
        if ($upgradeable_package) {
            foreach ($upgradeable_package as $item) {
                foreach ($item as $v) {
                    $upgrade[] = $v;
                }
            }
        }

        return [
            'main_package' => array_values($main_package),
            'upgradeable_package' => array_values($upgrade),
        ];
    }

    /**
     * 旅行團狀態
     * @param int $sold_qty 售賣人數
     * @param int $customer_num 收客人数
     * @param int $customer_min 成团人数
     * @return int
     */
    public static function group_status(int $sold_qty = 0, int $customer_num = 0, int $customer_min = 0)
    {
        $status = 0;
        if (bcmul($customer_min, 0.5) > $sold_qty) {
            $status = 1;//未成團--售卖人数<成团人数*50%
        }
        if (bcmul($customer_min, 0.5) <= $sold_qty && $sold_qty < $customer_min) {
            $status = 2;//快成團--成团人数*50%<=售卖人数<成团人数
        }
        if ($customer_min <= $sold_qty && $sold_qty <= $customer_num) {
            $status = 3;//已成團--成团人数<=售卖人数<=收客人数
        }
        if ($sold_qty >= $customer_min) {
            $status = 3;//已成團--售卖人数超过成团人数自动开下一个团,但仍显示"已成团"
        }
        if (($sold_qty == $customer_num) && $sold_qty != 0) {
            $status = 4;//已滿--售賣人數=收客人數
        }
        if ($sold_qty == 0) {
            $status = 1;
        }

        return $status;
    }
}