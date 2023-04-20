<?php
/**
 * Description :
 * Author      : Kobin
 * CreateTime  : 2021/8/6 上午11:26
 */

namespace app\api\service;


use app\api\model\independent\IndependentCombination;
use app\api\model\member\MemberCollection;
use app\api\model\member\MemberProductTrace;
use app\api\model\member\MemberRegistrationModel;
use app\api\model\member\Traveler;
use app\api\model\member\Wallet;
use app\api\model\product\ProductBaseModel;
use comm\model\independent\IndependentOrder;
use comm\model\member\MemberCertificationModel;
use comm\model\member\MemberManagementModel;
use comm\model\order\OrderModel;
use comm\model\product\ProductReleaseModel;
use comm\model\product\ProductSeriesModel;
use think\facade\Db;
use think\facade\Log;
use think\Model;

class MemberService extends ApiServiceBase
{
    /**
     * @param $user
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function me($user): array
    {
        $user->hidden([
            'ad_subscribe', 'update_time', 'delete_time', 'emergency_phone', 'emergency_name', 'emergency_relation'
        ])->toArray();

        $user['password'] = !empty($user['password']) ? 1 : 0;
        $user['pay_password'] = !empty($user['pay_password']) ? 1 : 0;
        $user['status'] = empty($user['expiry_date']) ? 'unregistered' : ((strtotime($user['expiry_date']) > time()) ? 'registered' : 'expired');

        $wallet = Wallet::getBalance($this->member_id);
        // TODO
        $coupon = 0;
        // TODO
        $points = 0;
        $collection_count = MemberCollection::getCollections($this->member_id, true);
        $trace_count = MemberProductTrace::getTrace($this->member_id, true);
        $order_count = $this->orderStat($this->device_id);

        return compact('user', 'wallet', 'coupon', 'points', 'collection_count', 'trace_count', 'order_count');
    }

    /**
     * @param $params
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function travelerList($params): array
    {
        $model = (new Traveler());
        $model = $model->where('member_id', $this->member_id);
        if ($params['type'] != 'all') {
            $model = $model->where('type', $params['type']);
        }
        $model = $model->with(['credentials']);
        $data = $model->paginate(['list_rows' => $params['limit'], 'page' => $params['page']])->toArray();
        foreach ($data['data'] as $key => $item) {
            $member = MemberManagementModel::getMemberStatus($item['phone'], $this->company_id);
            $data['data'][$key]['member']['id'] = $member['id'];
            $data['data'][$key]['member']['status'] = $member['member_status'];
            $data['data'][$key]['member']['expiry_date'] = $member['expiry_date'];
            $data['data'][$key]['member_config'] = config('system.member_config');
        }
        return $data;
    }

    /**
     * @param $params
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function collection($params): array
    {
        $currency = config('system.currency');
        $Model = new MemberCollection();
        $Model = $Model->where('member_id', $this->member_id);
        if (isset($params['form']) && !empty($params['form'])) {
            $Model = $Model->where('productable', $params['form']);
        }
        $ret = $Model
            ->with(['productInfo'])
            ->order('create_time', 'desc')
            ->paginate(['list_rows' => $params['limit'], 'page' => $params['page']])
            ->map(function (Model $item) use ($currency, $params) {
                return $this->handleReturnItem($item, $currency, $params);
            })
            ->toArray();
        $ret['data'] = array_merge(array_filter($ret['data']));
        return $ret;
    }

    public function collection_cancel($params): array
    {
        try {
            MemberCollection::where('id', 'in', $params['data'])->delete();
            return ['msg' => '成功'];
        } catch (\Exception $e) {
            return ['msg' => '错误:' . $e->getMessage()];
        }

    }

    /**
     * @param $params
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function trace($params): array
    {
        $currency = config('system.currency');
        $model = MemberProductTrace::where('member_id', $this->member_id);
        if (isset($params['form']) && !empty($params['form'])) {
            $model = $model->where('productable', $params['form']);
        }
        $ret = $model->with(['productInfo'])
            ->order('update_time', 'desc')
            ->group('product')
            ->paginate(['list_rows' => $params['limit'], 'page' => $params['page']])
            ->map(function (Model $item) use ($currency, $params) {
                return $this->handleReturnItem($item, $currency, $params);
            })
            ->toArray();
        $ret['data'] = array_filter($ret['data']);
        return $ret;
    }

    /**
     * @return string[]
     * @throws \Exception
     */
    public function trace_clear(): array
    {
        try {
            MemberProductTrace::where('member_id', $this->member_id)->delete();
            return ['true'];
        } catch (\Exception $exception) {
            throw new \Exception($exception->getMessage());
        }
    }

    /**
     * @param $item
     * @param $currency
     * @param $params
     * @return mixed
     */
    private function handleReturnItem($item, $currency, $params)
    {
        if ($item->productable === 'independent') {
            $item->name = $item->productInfo->name_tc;
            $item->product_code = '';
            $item->description = $item->productInfo->description_tc;
            $item->picture = $item->productInfo->picture;
            $item->days = $item->productInfo->days;
            $item->symbol = $currency[$item->productInfo->currency_id]['symbol'];
            $item->price = (float)$item->productInfo->sale_amount;
            $pd = IndependentCombination::where('id', $item->product)->field(['status', 'valid_start', 'valid_end'])->findOrEmpty();
            if ($pd->isEmpty()) {
                $item->status = 0;
            } else {
                $item->status = 0;
                if ($pd->status == 1) {
                    $today = date('Y-m-d');
                    if ($pd->valid_start <= $today && $pd->valid_end >= $today) {
                        $item->status = 1;
                    }
                }
            }
        } else {
            $children = ProductBaseModel::alias('b')
                ->leftjoin(full_table_name(new ProductSeriesModel()) . ' s', 'b.series_id=s.series_id')
                ->leftjoin(full_table_name(new ProductReleaseModel()) . ' r', 'r.product_base_id=b.product_id')
                ->where([
                    ['b.pid', '=', $item->product],
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
            $item->name = $item->productInfo->product_name;
            $item->product_code = $item->productInfo->product_code;
            $item->description = $item->productInfo->message;
            $item->picture = $item->productInfo->picture;
            $item->days = $item->productInfo->total_days;
            $item->symbol = $currency[$item->productInfo->currency]['symbol'];
            $item->price = $children ? bcdiv(min(array_column($children, 'member_fee')), 100) : 0;
            $item->earliest_date = $children ? date('Y-m-d', $children[0]['tour_date']) : '';
            $item->status = $item->productInfo->status;
            $item->product = $children[0]['product_id'];
        }
        unset($item->productInfo);
        if (isset($params['status']) && !empty($params['status'])) {
            if ($item['status'] == $params['status']) {
                return $item;
            }
        } else {
            return $item;
        }
    }

    /**
     * @param int $device_id
     * @param null $type
     * @param null $status
     * @return array
     */
    public function orderStat(int $device_id, $type = null, $status = null): array
    {
        $where = [
            ['member_id', '=', $this->member_id],
            //['type', '=', $device_id],
        ];
        $wherestatus = [];
        switch ($status) {
            case 1:
                $wherestatus[] = ['status', 'in', [1, 2]];
                break;
            case 2:
                $wherestatus[] = ['status', '=', 3];
                break;
            case 3:
                $wherestatus[] = ['status', '=', -1];
                break;
            case 4:
                $wherestatus[] = ['status', 'in', [4, 5, 6, 7]];
                break;
            case 8:
                $wherestatus[] = ['status', 'in', [8, 9]];
                break;
        }
        $lists = [];
        $models = [new OrderModel(), new IndependentOrder()];
        foreach ($models as $key => $model) {
            $list = $model->where($where)
                ->where($wherestatus)
                ->group('status')
                ->fieldRaw('count(*) as count,status')
                ->field('status')
                ->select()
                ->map(function ($item) {
                    switch ($item['status']) {
                        case 1:
                        case 2:
                            $item['status'] = 1;
                            $item['desc'] = '待支付';
                            break;
                        case 3:
                            $item['status'] = 2;
                            $item['desc'] = '待出行';
                            break;
                        case 8:
                        case 9:
                            $item['status'] = 8;
                            $item['desc'] = '已出行';
                            break;
                        default:
                            $item['status'] = 4;
                            $item['desc'] = '退款';
                    }
                    return $item;
                })->toArray();
            if (is_null($type)) {
                $lists = array_merge($lists, $list);
            } else {
                if ($key == $type) {
                    $lists = $list;
                    continue;
                }
            }
        }
        $data = [];
        foreach ($lists as $item) {
            if (isset($data[$item['status']])) {
                $data[$item['status']]['count'] += $item['count'];
            } else {
                $data[$item['status']] = $item;
            }
        }
        return array_values($data);
    }

    /**
     * @param $phone
     * @return string
     */
    public static function defaultNickNameByPhone($phone)
    {
        return substr($phone, 0, 3) . '****' . substr($phone, 7);
    }

    /**
     * @param $member_id
     * @param $registration_id
     * @param $device
     */
    public static function handleRegistration($member_id, $registration_id, $device)
    {
        Log::write('handleRegistration:' . 'member_id.' . $member_id . '.registration_id.' . $registration_id . '.device.' . $device);
        if (!empty($member_id) && !empty($registration_id) && !empty($device)) {
            if (in_array($device, array_keys(MemberRegistrationModel::$deviceIdToStr))) {
                $platform = MemberRegistrationModel::$deviceIdToStr[$device];
                MemberRegistrationModel::firstOrCreate(['member_id' => $member_id, 'registration_id' => $registration_id, 'platform' => $platform]);
            }
        }
    }
}