<?php

namespace app\api\controller\v1;

use app\api\controller\ApiBaseController;
use app\api\lib\BizException;
use app\api\model\member\Traveler as TravelerModel;
use app\api\model\member\TravelerCredentials;
use app\api\service\MemberService;
use app\api\validate\MemberValidator;
use comm\service\EncryptionService;
use think\App;
use think\Exception;
use think\facade\Log;
use think\Request;
use think\Response;
use think\validate\ValidateRule;

/**
 * 出行人
 *
 * Class Traveler
 * @package app\api\controller\v1
 */
class Traveler extends ApiBaseController
{
    private $service;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->service = new MemberService($this->company_id, $app->request->uid, (int)$app->request->header('Device', 3));
    }

    /**
     * 显示资源列表
     *
     * @param Request $request
     * @return Response
     * @throws \think\db\exception\DbException
     */
    public function index(Request $request): Response
    {
        $params = $request->get();
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, MemberValidator::class . '.' . MemberValidator::TRAVELER);
            return $this->service->travelerList($params);
        });
    }

    /**
     * 保存新建的资源
     *
     * @return Response
     */
    public function save(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            $params = $this->validating($request);
            $model = new TravelerModel();
            $model->startTrans();
            try {
                $params['member_id'] = $request->uid;
                $this->checkDefaultAndPhone($request->uid, $params);
                $ret = TravelerModel::create($params);
                $ret->credentials()->saveAll($this->handleCredentials($params, $ret->id));
                $model->commit();
                return $ret->id;
            } catch (\Exception $exception) {
                $model->rollback();
                BizException::throwException($exception->getCode(), $exception->getMessage());
            }
        });

    }

    /**
     * 显示指定的资源
     *
     * @param int $id
     * @return Response
     */
    public function read(int $id): Response
    {
        return $this->apiResponse(function () use ($id) {
            $tr = TravelerModel::with('credentials')->findOrEmpty($id)->toArray();
            if (empty($tr)) {
                BizException::throwException(23002);
            }
            return $tr;
        });

    }

    /**
     * 保存更新的资源
     *
     * @param int $id
     * @return Response
     */
    public function update(int $id, Request $request): Response
    {
        return $this->apiResponse(function () use ($request, $id) {
            $params = $this->validating($request);
            $model = new TravelerModel();
            $model->startTrans();
            try {
                $this->checkDefaultAndPhone($request->uid, $params, $id);
                $tr = TravelerModel::findOrEmpty($id);
                if (empty($tr->toArray())) {
                    BizException::throwException(23002);
                }
                $ret = $tr->save($params);
                $tr->credentials()->saveAll($this->handleCredentials($params, $id));
                $model->commit();
                return $ret;
            } catch (\Exception $exception) {
                $model->rollback();
                BizException::throwException($exception->getCode(), $exception->getMessage());
            }
        });
    }

    /**
     * @param $params
     * @param int $id
     * @return array
     */
    private function handleCredentials($params, $id = 0)
    {
        $ret = [];
        if (!empty($params['credentials'])) {
            $ids = array_filter(array_column($params['credentials'], 'id'));
            if (!empty($ids)) {
                (new TravelerCredentials())->where('id', 'not in', $ids)->where(['traveler_id' => $id])->delete();
            }
            foreach ($params['credentials'] as $key => $value) {
                $ret[$key]['id'] = $value['id'];
                $ret[$key]['credentials_type'] = $value['credentials_type'];
                $ret[$key]['credentials_number'] = $value['credentials_number'];
                $ret[$key]['issue_code'] = isset($value['issue_code']) && !empty($value['issue_code']) ? $value['issue_code'] : '';
                $ret[$key]['expire_date'] = isset($value['expire_date']) && !empty($value['expire_date']) ? $value['expire_date'] : 0;
            }
        }
        return $ret;
    }

    /**
     * @param $uid
     * @param $params
     * @param int $id
     * @throws Exception
     */
    private function checkDefaultAndPhone($uid, $params, $id = 0)
    {
        $ser = new EncryptionService();
        if ($params['is_default'] == 1) {
            $model = (new TravelerModel())->where(['member_id' => $uid]);
            if ($id !== 0) {
                $model = $model->where('id', '<>', $id);
            }
            $model->update(['is_default' => 0]);
        }
        if (!empty($params['phone'])) {
            $model = (new TravelerModel())->where(['member_id' => $uid, 'phone' => $ser->encrypt($params['phone'])]);
            if ($id !== 0) {
                $model = $model->where('id', '<>', $id);
            }
            $tr = $model->findOrEmpty()->toArray();
            if (!empty($tr)) {
                BizException::throwException(23001, '手机号为' . $params['phone'] . "的出行人已存在{$tr['id']}。");
            }
        }
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return Response
     */
    public function delete(int $id): Response
    {
        return $this->apiResponse(function () use ($id) {
            try {
                $data = TravelerModel::with('credentials')->findOrEmpty($id);
                if (empty($data->toArray())) {
                    BizException::throwException(23002);
                }
                return $data->together(['credentials'])->delete();
            } catch (\Exception $exception) {
                BizException::throwException(9000, $exception->getMessage());
            }
        });

    }

    /**
     * 验证数据
     *
     * @param Request $request
     * @return array
     */
    public function validating(Request $request): array
    {
        $rules = [
            'is_default' => 'require|in:0,1',
            'last_name_cn' => 'max:32',
            'first_name_cn' => 'max:32',
            'last_name_en' => 'max:32',
            'first_name_en' => 'max:32',
            'birthday' => 'require',
            'gender' => 'require|integer',
            'email' => 'email',
            'area_code' => 'max:32|in:86,852,853,886',
            'nation_code' => 'require|in:86,852,853,886',
            'phone' => 'max:32',
            'credentials' => 'array',
            'type' => 'require|in:adult,child,baby',
//            'credentials_type' => ValidateRule::isRequire()->in(array_keys(config('system.credentials'))),
//            'credentials_number' => 'require|max:32',
//            'birth_year' => 'require|integer|<=:' . date('Y'),
//            'birth_month' => 'require|integer|between:1,12',
        ];
        $message = [
            'area_code' => 'area_code in:86,852,853,886',
            'nation_code' => 'nation_code in:850,86,856,861,851,1001,852',
        ];

        $data = $request->only(array_keys($rules));
        $this->validate($data, $rules, $message);

        $data['member_id'] = $request->uid;
        return $data;
    }

}
