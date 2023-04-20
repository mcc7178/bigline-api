<?php

namespace app\api\controller\v1\Setting;

use app\api\controller\ApiBaseController;
use app\api\lib\BizException;
use app\api\Request;
use app\api\service\captcha\CaptchaService;
use app\api\validate\Auth as AuthValidate;
use comm\constant\CK;
use app\facade\Redis;
use app\api\model\member\MemberModel;
use think\exception\ValidateException;
use think\facade\Log;
use think\validate\ValidateRule;

/**
 * 修改登錄密碼
 * Class ResetLoginPassword
 * @package app\api\controller\v1\Setting\SecurityCenter
 */
class ResetLoginPassword extends ApiBaseController
{
    // 间隔时间，单位：秒,默认1分钟只能获取一次
    public const CODE_FREQ = 60;

    // 验证码有效期，单位：秒
    public const CODE_EXPIRE = 60 * 5;

    /**
     * 生成驗證碼
     * @return mixed
     * @throws \Exception
     */
    public function captcha()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $user = $request->user();
            $user_id = $user->id;
            $code = random_int(1000, 9999);
            $key = CK::RESET_LOGIN_PASSWORD_VERIFY . $user_id;
            if (Redis::exists($key)) {
                Redis::del($key);
            }
            Redis::setex($key, self::CODE_EXPIRE, $code);
            return ['msg' => '生成成功', 'code' => $code];
        });
    }

    /**
     * 發送短信驗證碼
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
                $code = $this->sendSmsCode($data);
                return ['msg' => '發送成功', 'code' => $code];
            } catch (ValidateException $exception) {
                BizException::throwException(9000, $exception->getMessage());
            }
        });
    }

    /**
     * 數據校驗
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function check()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            $data = $request->post();
            $user = $request->user();
            $user_id = $user->id;
            $member = MemberModel::findOrFail($user_id)->toArray();
            if ($member['phone'] != $data['phone']) {
                BizException::throwException(31002);
            }
//        $this->validate($data, AuthValidate::class . '.' . AuthValidate::SCENE_PWD_RESET);
            $this->validating($data, true, true);
            $verify_key = CK::RESET_LOGIN_PASSWORD_VERIFY . $user_id;
            $sms_key = CK::RESET_LOGIN_PASSWORD_SMS . $data['area_code'] . '_' . $data['phone'];
            if (((new CaptchaService())->check($data['captcha'], $data['key'])) === false) {
                BizException::throwException(10006);
            }
            if (Redis::get($sms_key) != $data['sms_code']) {
                BizException::throwException(10007);
            }
            Redis::del([$verify_key, $sms_key]);
            Redis::setex(CK::RESET_LOGIN_PASSWORD_VERIFIED . $user_id, self::CODE_EXPIRE, time());
            return ['msg' => '校驗成功'];
        });
    }

    /**
     * 修改登錄密碼
     * @return mixed
     */
    public function update()
    {
        $request = $this->request;
        return $this->apiResponse(function () use ($request) {
            try {
                $data = $request->post();
                $user = $request->user();
                $user_id = $user->id;
                $verify_key = CK::RESET_LOGIN_PASSWORD_VERIFIED . $user_id;
                if (!Redis::exists($verify_key)) {
                    BizException::throwException(9006);
                }
                $member = MemberModel::findOrFail($user_id);
                $this->validate($data, AuthValidate::class . '.' . AuthValidate::SCENE_RESET_LOGIN_PWD);
                $res = $member->save(['password' => $data['password']]);
                if ($res) {
                    $msg = '修改登錄密碼成功';
                    $status = 1;
                    Redis::del($verify_key);
                } else {
                    $msg = '修改登錄密碼失敗';
                    $status = 0;
                }
                return ['msg' => $msg, 'status' => $status];
            } catch (ValidateException $exception) {
                BizException::throwException(9000, $exception->getMessage());
            }
        });
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
        $key = CK::RESET_LOGIN_PASSWORD_SMS . $data['area_code'] . '_' . $data['phone'];

        if (Redis::ttl($key) > self::CODE_EXPIRE - self::CODE_FREQ) {
            BizException::throwException(10002);
        }
        Log::info('SMS:' . $code);

        // Todo 此处发信息
        event('SendSms', ['area_code' => $data['area_code'], 'phone' => $data['phone'], 'msg' => '【大航假期】您的驗證碼為:' . $code . ',' . self::CODE_EXPIRE / 60 . '分鐘內有效。']);

        Redis::setex($key, self::CODE_EXPIRE, $code);
        return $code;
    }

    /**
     * 验证数据
     * @param array $data
     * @param bool $sms_code 是否验证短信code
     * @param bool $captcha 是否验证code
     */
    public function validating(array $data, bool $sms_code = false, bool $captcha = false): void
    {
        $areas = array_column(config('system.area_code'), null, 'code');
        $rule['area_code'] = ValidateRule::in(implode(',', array_keys($areas)));
        if ($phone_rule = $areas[$data['area_code']]['validate'] ?? '') {
            $rule['phone'] = 'require|' . $phone_rule;
        }

        if ($sms_code) {
            $rule['sms_code'] = 'require|length:6';
        }
        if ($captcha) {
            $rule['key'] = 'require';
            $rule['captcha'] = 'require|length:4';
        }

        $this->validate($data, $rule);
    }
}