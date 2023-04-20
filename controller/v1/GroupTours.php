<?php declare(strict_types=1);

namespace app\api\controller\v1;

use app\api\controller\Controller;
use comm\model\product\ProductBaseModel;
use comm\model\product\ProductReleaseModel;
use think\db\Query;
use think\Request;
use think\Response;

/**
 * 跟团游
 *
 * Class GroupTours
 * @package app\api\controller\v1
 */
class GroupTours extends Controller
{

    /**
     * 显示资源列表
     *
     * @param Request $request
     * @return Response
     * @throws \think\db\exception\DbException
     */
    public function index(Request $request): Response
    {
        $get = $request->get();

        $baseWhere = [
            ['pid', '=', 0],
        ];
        if($this->company_id){
            $baseWhere[] = ['company_id', '=', $this->company_id];
        }

        if ($request->has('earliest_date', 'get', true)) {
            $baseWhere[] = ['earliest_date', '>=', strtotime($get['earliest_date'])];
        } else {    // 查询有出团日期的
            $baseWhere[] = ['earliest_date', '>', 0];
        }

        if ($request->has('latest_date', 'get', true)) {
            $baseWhere[] = ['latest_date', '<=', $get['latest_date']];
        }

        if ($request->has('minimum_price', 'get', true)) {
            $baseWhere[] = ['minimum_price', '>=', $get['minimum_price'] * 100];
        }

        if ($request->has('maximum_price', 'get', true)) {
            $baseWhere[] = ['maximum_price', '<=', $get['maximum_price'] * 100];
        }

        if (!empty($get['total_days'])) {
            $baseWhere[] = ['total_days', '=', $get['total_days']];
        }

        $locationWhere = [];
        if (!empty($get['cate_code'])) {
            $locationWhere = ['cate_code' => $get['cate_code']];
        }

        $product = ProductBaseModel::hasWhere('location', $locationWhere)
            ->with('attachment')
            ->where($baseWhere)
            ->paginate($get['limit'] ?? null)
            ->map(function ($item) {
                $item['pictures'] = $item['attachment']->column('att_dir');
                unset($item['attachment']);
                return $item;
            })
            ->visible(['product_id', 'product_name', 'product_code', 'total_days', 'picture', 'pictures', 'update_time', 'minimum_price', 'maximum_price']);

        return $this->response->paginator($product);
    }

    /**
     * 详情
     *
     * @param int $id
     * @return Response
     */
    public function read(int $id): Response
    {
        $product = ProductBaseModel::findOrFail($id);
        return $this->response->item($product);
    }

    /**
     * 出团日历
     *
     * @param int $id
     * @return Response
     */
    public function travel_calendar(int $id): Response
    {
        // 查询具体日期
        $res = ProductBaseModel::findOrFail($id)
            ->children()
            ->field('product_id')
            ->with(['release' => fn(Query $query) => $query->field(['product_base_id', 'tour_date', ProductReleaseModel::$base_parent_price => 'price'])])
            ->select();

        $prices = [];
        foreach ($res as $item) {
            [$year, $month, $day] = array_map(static fn($val) => (int)$val, explode('-', $item['release']['tour_date']));

            if (!isset($prices[$year]['months'][$month]['month'])) {
                $prices[$year]['months'][$month]['month'] = $month;
            }

            if (!isset($prices[$year]['months'][$month]['minimum_price']) || $item['release']['price'] < $prices[$year]['months'][$month]['minimum_price']) {
                $prices[$year]['months'][$month]['minimum_price'] = $item['release']['price'];
            }

            $prices[$year]['months'][$month]['days'][] = [
                'day' => $day,
                'price' => $item['release']['price']
            ];
        }

        $tmp = [];
        foreach ($prices as $year => $item) {
            $tmp[] = [
                'year' => $year,
                'months' => array_values($item['months'])
            ];
        }

        return $this->response->item($tmp);
    }
}