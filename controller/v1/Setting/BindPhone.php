<?php

namespace app\api\controller\v1\Setting;

use app\api\controller\ApiBaseController;
use app\api\controller\Controller;
use app\api\lib\BizException;
use app\api\Request;
use app\facade\Redis;
use app\api\model\member\MemberModel;
use comm\constant\CK;
use app\api\validate\Auth as AuthValidate;
use think\exception\ValidateException;
use think\validate\ValidateRule;

/**
 * 綁定手機號
 * Class BindPhone
 * @package app\api\controller\v1\Setting
 */
class BindPhone extends ApiBaseController
{
    // 间隔时间，单位：秒,默认1分钟只能获取一次
    public const CODE_FREQ = 60;

    // 验证码有效期，单位：秒
    public const CODE_EXPIRE = 60 * 5;

    /**
     * 校驗登錄密碼
     * @return mixed
     */
    public function check()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $password = $request->post('password', '');
            if (empty($password)) {
                BizException::throwException(9005);
            }
            $user = $request->user();
            $user_id = $user->id;
            $info = MemberModel::findOrFail($user_id)->toArray();
            if ($info['password'] != $password) {
                BizException::throwException(31001);
            }
            $key = CK::RESET_PHONE_VERIFY . $user_id;
            Redis::setex($key, self::CODE_EXPIRE, $key);
            return ['msg' => '验证成功'];
        });
    }

    /**
     * 發送驗證碼
     * @return mixed
     * @throws \Exception
     */
    public function send_sms()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            try {
                $data = $request->post();
                $this->validate($data, AuthValidate::class . '.' . AuthValidate::SCENE_SMS_SEND);
                $this->validating($data);
                $user = $request->user();
                $user_id = $user->id;
                $key = CK::RESET_PHONE_VERIFY . $user_id;
                if (!Redis::exists($key)) {
                    BizException::throwException(10007);
                }
                $code = $this->sendSmsCode($data);
                return ['msg' => '發送成功', 'code' => $code];
            } catch (ValidateException $exception) {
                BizException::throwException(9000, $exception->getMessage());
            }
        });
    }

    /**
     * 綁定手機號
     * @return mixed
     */
    public function update()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            try {
                $data = $request->post();
                $this->validate($data, AuthValidate::class . '.' . AuthValidate::SCENE_SMS_SEND);
                $this->validating($data);
                $user = $request->user();
                $user_id = $user->id;
                $key = CK::RESET_PHONE_VERIFY . $user_id;
                if (!Redis::exists($key)) {
                    BizException::throwException(9006);
                }
                $this->verify_key($user_id);
                $this->checkSmsCode($data);
                $memberModel = MemberModel::findOrFail($user_id);
                $res = $memberModel->save(['phone' => $data['phone']]);
                if ($res) {
                    $msg = '修改成功';
                    $key_sms = CK::RESET_PHONE_SMS . $data['area_code'] . '_' . $data['phone'];
                    $key_verify = CK::RESET_PHONE_VERIFY . $user_id;
                    Redis::del([$key_sms, $key_verify]);
                } else {
                    $msg = '修改失敗';
                }
                return ['msg' => $msg];
            } catch (ValidateException $exception) {
                BizException::throwException(9000, $exception->getMessage());
            }
        });
    }

    /**
     * 校驗請求
     * @param $user_id
     */
    private function verify_key($user_id)
    {
        $key = CK::RESET_PHONE_VERIFY . $user_id;
        if (!Redis::exists($key)) {
            BizException::throwException(9006);
        }
    }

    /**
     * 發送短信驗證碼
     * @param $data
     * @return int
     * @throws \Exception
     */
    private function sendSmsCode($data)
    {
        $code = random_int(100000, 999999);
        $key = CK::RESET_PHONE_SMS . $data['area_code'] . '_' . $data['phone'];

        if (Redis::ttl($key) > self::CODE_EXPIRE - self::CODE_FREQ) {
            BizException::throwException(10002);
        }

        // Todo 此处发信息
        event('SendSms', ['area_code' => $data['area_code'], 'phone' => $data['phone'], 'msg' => '【大航假期】您的驗證碼為:' . $code . ',' . self::CODE_EXPIRE / 60 . '分鐘內有效。']);

        Redis::setex($key, self::CODE_EXPIRE, $code);
        return $code;
    }

    /**
     * 校驗短信驗證碼
     * @param $data
     */
    private function checkSmsCode($data)
    {
        $this->validating($data, true);

        $key = CK::RESET_PHONE_SMS . $data['area_code'] . '_' . $data['phone'];
        if ((int)Redis::get($key) !== (int)$data['sms_code']) {
            BizException::throwException(10007);
        }
    }

    /**
     * 验证数据
     * @param array $data
     * @param bool $verify_code 是否验证code
     */
    public function validating(array $data, bool $verify_code = false): void
    {
        $areas = array_column(config('system.area_code'), null, 'code');
        $rule['area_code'] = ValidateRule::in(implode(',', array_keys($areas)));
        if ($phone_rule = $areas[$data['area_code']]['validate'] ?? '') {
            $rule['phone'] = 'require|' . $phone_rule;
        }

        if ($verify_code) {
            $rule['sms_code'] = 'require|length:6';
        }

        $this->validate($data, $rule);
    }
}