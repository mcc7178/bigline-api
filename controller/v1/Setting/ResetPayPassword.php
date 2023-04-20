<?php

namespace app\api\controller\v1\Setting;

use app\api\controller\ApiBaseController;
use app\api\controller\Controller;
use app\api\lib\BizException;
use app\api\Request;
use app\api\validate\Auth as AuthValidate;
use app\facade\Redis;
use comm\constant\CK;
use app\api\model\member\MemberModel;
use think\Exception;
use think\exception\ValidateException;

/**
 * 重設支付密碼
 * Class ResetPayPassword
 * @package app\api\controller\v1\Setting\SecurityCenter
 */
class ResetPayPassword extends ApiBaseController
{
    // 间隔时间，单位：秒,默认1分钟只能获取一次
    public const CODE_FREQ = 60;

    // 验证码有效期，单位：秒
    public const CODE_EXPIRE = 60 * 5;

    /**
     * 獲取基礎信息
     * @return mixed
     */
    public function info()
    {
        $request = $this->request;
        $user = $request->user();
        $user_id = $user->id;
        $info = MemberModel::field('phone,area_code')->findOrFail($user_id)->toArray();
        return $this->apiResponse(function () use ($info) {
            return $info;
        });
    }

    /**
     * 發送驗證碼
     * @return mixed
     */
    public function send_sms()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $user = $request->user();
            $user_id = $user->id;
            $info = MemberModel::field('phone,area_code')->findOrFail($user_id);
            $code = random_int(100000, 999999);
            $key = CK::RESET_PAY_PASSWORD_SMS . $info->phone;

            if (Redis::ttl($key) > self::CODE_EXPIRE - self::CODE_FREQ) {
                BizException::throwException(10002);
            }

            // Todo 此处发信息
            event('SendSms', ['area_code' => $info->area_code, 'phone' => $info->phone, 'msg' => '【大航假期】您的驗證碼為:' . $code . ',' . self::CODE_EXPIRE / 60 . '分鐘內有效。']);

            Redis::setex($key, self::CODE_EXPIRE, $code);
            return ['msg' => '發送成功', 'code' => $code];
        });
    }

    /**
     * 數據驗證
     * @return mixed
     */
    public function check()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $user = $request->user();
            $user_id = $user->id;
            $sms_code = $request->post('sms_code');
            $info = MemberModel::field('phone')->findOrFail($user_id);
            $key = CK::RESET_PAY_PASSWORD_SMS . $info->phone;
            if (!Redis::exists($key)) {
                BizException::throwException(9006);
            }
            if (Redis::get($key) != $sms_code) {
                BizException::throwException(10007);
            }
            $valid_key = CK::RESET_PAY_PASSWORD_VALID . $info->phone;
            Redis::setex($valid_key, self::CODE_EXPIRE, 1);
            return ['msg' => '驗證成功', 'status' => 1];
        });
    }

    /**
     * 設置支付密碼
     * @return mixed
     */
    public function save()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            try {
                $post = $request->post();
                $user = $request->user();
                $user_id = $user->id;
                $info = MemberModel::findOrFail($user_id);
                $valid_key = CK::RESET_PAY_PASSWORD_VALID . $info->phone;
                if (!Redis::exists($valid_key)) {
                    BizException::throwException(9006);
                }
                $this->validate($post, AuthValidate::class . '.' . AuthValidate::SCENE_SET_PAY_PWD);
                $res = $info->save(['pay_password' => $post['pay_password']]);
                if ($res) {
                    $data = [
                        'msg' => '設置成功',
                        'status' => 1,
                        'pay_password' => $post['pay_password']
                    ];
                    $key = CK::RESET_PAY_PASSWORD_SMS . $info->phone;
                    Redis::del($valid_key);
                    Redis::del($key);
                } else {
                    $data = [
                        'msg' => '設置失败',
                        'status' => 0,
                    ];
                }
                return $data;
            } catch (ValidateException $exception) {
                BizException::throwException(9000, $exception->getMessage());
            }
        });
    }
}