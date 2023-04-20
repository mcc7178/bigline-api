<?php

namespace app\api\controller\v1\Setting;

use app\api\controller\ApiBaseController;
use app\api\lib\BizException;
use app\api\Request;
use app\api\model\member\MemberModel;
use app\api\service\UploaderService;
use think\exception\ValidateException;

/**
 * 个人信息
 * Class PersonalInformation
 * @package app\api\controller\v1\Setting
 */
class PersonalInformation extends ApiBaseController
{
    protected array $rule = [
        'avatar' => 'max:255',
        'last_name_cn' => 'max:32',
        'first_name_cn' => 'max:32',
        'last_name_en' => 'max:64',
        'first_name_en' => 'max:64',
        'nickname' => "min:1|max:30|chsAlphaNum",
        'title' => 'in:1,2',
        'birthday' => 'date',
    ];

    protected array $message = [
        'nickname' => '昵称只能是汉字、字母或数字'
    ];

    /**
     * 个人信息
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function info()
    {
        $request = $this->request;
        $user = $request->user();
        $user_id = $user->id;
        $field = 'avatar,last_name_cn,first_name_cn,last_name_en,first_name_en,nickname,title,birthday';
        $info = MemberModel::field($field)->findOrFail($user_id);
        return $this->apiResponse(function () use ($info) {
            return $info;
        });
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function upload_avatar()
    {
        $request = $this->request;
        $res = (new UploaderService())->upload($request);
        return $this->apiResponse(function () use ($res) {
            return $res;
        });
    }

    /**
     * 保存數據
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function save()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            try {
                $data = $request->post();
                $user = $request->user();
                $user_id = $user->id;
                $this->validate($data, $this->rule);
                $info = MemberModel::findOrFail($user_id);
                $res = $info->save($data);
                $data = [
                    'msg' => '編輯成功',
                    'status' => 1
                ];
                if ($res == false) {
                    $data = [
                        'msg' => '編輯失敗',
                        'status' => 0
                    ];
                }
                return $data;
            } catch (ValidateException $exception) {
                BizException::throwException(9000, $exception->getMessage());
            }
        });
    }
}