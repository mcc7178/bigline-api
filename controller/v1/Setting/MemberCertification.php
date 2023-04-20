<?php

namespace app\api\controller\v1\Setting;

use app\api\controller\ApiBaseController;
use app\api\lib\BizException;
use app\api\Request;
use app\api\service\UploaderService;
use comm\model\member\MemberCertificationModel;
use app\api\validate\MemberCertificationValidator;
use comm\model\member\MemberManagementModel;
use think\exception\ValidateException;

/**
 * 會員實名認證
 * Class MemberCertification
 * @package app\api\controller\v1\Setting
 */
class MemberCertification extends ApiBaseController
{
    /**
     * 身份證證件類型
     * @return mixed
     */
    public function id_types()
    {
        return $this->apiResponse(function () {
            return [
                ['key' => 1, 'value' => '大陸身份證'],
                ['key' => 2, 'value' => '香港身份證'],
                ['key' => 3, 'value' => '澳門身份證'],
            ];
        });
    }

    /**
     * 國家代碼
     * @return mixed
     */
    public function area_codes()
    {
        return $this->apiResponse(function () {
            return [
                ['key' => 86, 'value' => '中國大陸'],
                ['key' => 852, 'value' => '中國香港'],
                ['key' => 853, 'value' => '中國澳門'],
                ['key' => 866, 'value' => '中國台灣'],
                ['key' => 0, 'value' => '其他國家和地區'],
            ];
        });
    }

    /**
     * 圖片上傳
     * @return array
     */
    public function upload()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            return (new UploaderService())->upload($request);
        });
    }

    /**
     * 支付設置信息
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function pay_setting()
    {
        $request = $this->request;
        $user = $request->user();
        $user_id = $user->id;
        $user_info = MemberManagementModel::field('phone,pay_password')->findOrEmpty($user_id)->toArray();
        $certification = MemberCertificationModel::where('member_id', $user_id)->field('id,type')->group('type')->select()->toArray();
        $data = [
            'certification' => array_column($certification, 'type'),
            'bind_phone' => !empty($user_info['phone']) ? substr_replace($user_info['phone'], '****', 3, 4) : '',
            'pay_password' => !empty($user_info['pay_password']) ? 1 : 0,
        ];
        return $this->apiResponse(function () use ($data) {
            return $data;
        });
    }

    /**
     * 基础信息
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function info()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $user = $request->user();
            $user_id = $user->id;
            $type = $request->get('type', 1);
            if (!in_array($type, [1, 5, 6])) {
                BizException::throwException(9005, 'type');
            }
            $where = [
                ['member_id', '=', $user_id]
            ];
            if ($type == 1) {
                $where[] = ['type', 'in', [1, 2, 3]];
            } else {
                $where[] = ['type', '=', $type];
            }
            $info = MemberCertificationModel::where($where)->select()->toArray();
            return $info;
        });
    }

    /**
     * 保存数据
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function save()
    {
        $request = $this->request;
        $user = $request->user();
        $user_id = $user->id;
        $params = $request->post();

        try {
            if (validate(MemberCertificationValidator::class)->check($params)) {
                if ($id = $request->post('id')) {
                    $model = MemberCertificationModel::find($id);
                } else {
                    $params['member_id'] = $user_id;
                    $model = new MemberCertificationModel();
                }
                $res = $model->save($params);
                return $this->apiResponse(function () use ($res) {
                    return $res;
                });
            }
        } catch (ValidateException $exception) {
            BizException::throwException(9000, $exception->getMessage());
        }
    }
}