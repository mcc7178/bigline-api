<?php

/**
 * description : 自由行首页 产品详情 搜索
 * author      : Kobin
 * CreateTime  : 2021/8/3 上午11:32
 * description : 自由行
 */

namespace app\api\controller\v1\Independent;


use app\api\controller\ApiBaseController;
use app\api\job\SearchRecordsService;
use app\api\lib\BizException;
use app\api\model\member\MemberCollection;
use app\api\model\member\MemberProductTrace;
use app\api\model\member\MemberSearchModel;
use app\api\service\IndependentService;
use app\api\validate\CombinationValidator;
use app\api\validate\IndependentSearchValidator;
use comm\constant\CN;
use think\App;

class IndependentIndex extends ApiBaseController
{
    private IndependentService $service;

    /**
     * IndependentIndex constructor.
     * @param App $app
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->service = new IndependentService($this->company_id, $this->request->uid, (int)$app->request->header('Device', 3));
    }

    /**
     * @return mixed
     */
    public function index()
    {
        return $this->cache(CN::TEN_MINUTE)->apiResponse(function () {
            return $this->service->index();
        });
    }

    /**
     * @param $id
     * @return mixed
     */
    public function detail($id)
    {
        return $this->cache()->apiResponse(function () use ($id) {
            if (empty($id)) {
                BizException::throwException(9005, 'ID缺失。');
            }
            return $this->service->detail($id, $this->request->uid);
        });
    }

    /**
     * 获取项目某日期的价格
     * @return mixed
     */
    public function price()
    {
        $params = $this->request->get();
        return $this->cache()->apiResponse(function () use ($params) {
            $this->validate($params, CombinationValidator::class . '.' . CombinationValidator::PRICE);
            return $this->service->price($params);
        });
    }

    /**
     * @param $id
     * @return mixed
     */
    public function calendar($id)
    {
        $params = $this->request->get();
        return $this->cache()->apiResponse(function () use ($id, $params) {
            if (empty($id)) {
                BizException::throwException(9005, 'ID缺失。');
            }
            $this->validate($params, CombinationValidator::class . '.' . CombinationValidator::CALENDAR);
            return $this->service->productPriceCalendar($id, $params);
        });
    }

    /**
     *
     * @return mixed
     */
    public function before()
    {
        return $this->apiResponse(function () {
            return $this->service->beforeSearch();
        });
    }

    /**
     * @return mixed
     */
    public function search()
    {
        $params = $this->request->post();
        return $this->cache()->apiResponse(function () use ($params) {
            $this->validate($params, IndependentSearchValidator::class . '.' . IndependentSearchValidator::SCENE_SEARCH);
            // 异步处理搜索记录
            if (isset($params['words']) && $params['words'] != '' && $this->request->uid) {
                queue(SearchRecordsService::class,
                    [
                        'type' => MemberSearchModel::TYPE_INDEPENDENT,
                        'member_id' => $this->request->uid,
                        'company_id' => $this->company_id,
                        'words' => $params['words']
                    ]
                );
            }
            return $this->service->search($params, $this->request->uid);
        });
    }

    public function historyDelete()
    {
        $params = $this->request->post();
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, IndependentSearchValidator::class . '.' . IndependentSearchValidator::SCENE_DELETE_SEARCH_HISTORY);
            return $this->service->deleteHistory($params, $this->request->uid);
        });
    }

    /**
     * 收藏/取消收藏
     * @param $id
     * @return mixed
     */
    public function collect($id)
    {
        return $this->apiResponse(function () use ($id) {
            return MemberCollection::productCollect($this->request->uid, MemberProductTrace::TYPE_INDEPENDENT, $id);
        });
    }


}