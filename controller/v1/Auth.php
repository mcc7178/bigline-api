<?php declare(strict_types=1);

namespace app\api\controller\v1;

use app\api\controller\ApiBaseController;
use app\api\controller\Controller;
use app\api\lib\BizException;
use app\api\service\captcha\CaptchaService;
use app\api\service\MemberService;
use app\facade\Redis;
use comm\constant\CK;
use comm\constant\CN;
use app\api\model\member\MemberModel;
use comm\service\EncryptionService;
use Firebase\JWT\JWT;
use think\Exception;
use think\facade\Lang;
use think\facade\Log;
use think\Request;
use think\Response;
use think\validate\ValidateRule;
use app\api\validate\Auth as AuthValidate;

class Auth extends ApiBaseController
{
    // 间隔时间，单位：秒,默认1分钟只能获取一次
    public const CODE_FREQ = 60;

    // 验证码有效期，单位：秒，默认15分钟
    public const CODE_EXPIRE = 5;

    /**
     * 获取区号
     *
     * @return Response
     */
    public function area_code(): Response
    {
        return $this->apiResponse(function () {
            return config('system.area_code');
        });
    }


    /**
     * 註冊
     *
     * @param Request $request
     * @return Response
     */
    public function register(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            $data = $request->post();
            $this->validate($data, AuthValidate::class . '.' . AuthValidate::REGISTER);
            $this->validating($data);
            $this->checkSmsCode($request);
            $member = MemberModel::findByPhone($this->company_id, $data['phone'], ['id'], false, 0);
            if (is_null($member)) {
                $data['company_id'] = $this->company_id;
                $data['nickname'] = MemberService::defaultNickNameByPhone($data['phone']);
                $data['status'] = 1;
                $member = MemberModel::create($data);
            } else {
                BizException::throwException(20033);
            }
            MemberService::handleRegistration($member['id'], $data['registration_id'], $request->header('Device', '3'));
            return $this->respondWithToken($member['id']);
//            $code = $this->sendSmsCode($data);
        });
    }


    /**
     * 获取验证码
     *
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function sms_code(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            $data = $request->post();
            $this->validate($data, AuthValidate::class . '.' . AuthValidate::SCENE_SMS_SEND);
            $this->validating($data);
            $code = $this->sendSmsCode($data);
            return $code;
        });

//        return $this->response->created();
    }

    /**
     * 验证数据
     *
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

    /**
     * 登陆
     * @param Request $request
     * @return array|\think\response\Json
     */
    public function login(Request $request)
    {
        return $this->apiResponse(function () use ($request) {
            $data = $request->post();
            $this->validating($data, true);
//            $this->checkSmsCode($request);
            $member = MemberModel::findByPhone($this->company_id, $data['phone'], ['id'], false, 0);
            if (is_null($member)) {
                $data['company_id'] = $this->company_id;
                $data['nickname'] = MemberService::defaultNickNameByPhone($data['phone']);
                $data['status'] = 1;
                $member = MemberModel::create($data);
            }
            MemberService::handleRegistration($member['id'], $data['registration_id'], $request->header('Device', '3'));
            return $this->respondWithToken($member['id']);
        });

    }

    /**
     * @param Request $request
     */
    private function checkSmsCode($request)
    {
        $data = $request->post();
        $this->validating($data, true);

        $key = CK::LOGIN_CODE . $data['area_code'] . '_' . $data['phone'];
        if ((int)Redis::get($key) !== (int)$data['sms_code']) {
            BizException::throwException(10007);
        }
        Redis::del($key);
    }

    /**
     * 5axyY6xu0UdYapDbPGXL0w==
     * @param Request $request
     * @return Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function login_pwd(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            $encrypt = new EncryptionService();
            $data = $request->post();
            $this->validating($data, false);
            $model = MemberModel::where(['phone' => $encrypt->encrypt($data['phone']), 'company_id' => $this->company_id]);
            $member = $model->findOrEmpty()->toArray();
            MemberService::handleRegistration($member['id'], $data['registration_id'], $request->header('Device', '3'));
            if (empty($member)) {
                BizException::throwException(10004);
            } else {
                if ($member['password'] == $data['password']) {
                    return $this->respondWithToken($member['id']);
                } else {
                    BizException::throwException(10005);
                }
            }
        });

    }

    /**
     * 生成toke
     *
     * @param $uid
     * @return string
     */
    public function token($uid): string
    {
        $config = config('jwt');
        $payload = array(
            "iss" => '',        //签发者 可以为空
            "aud" => '',          //面象的用户，可以为空
            "iat" => time(),      //签发时间
            "nbf" => time() + $config['nbf'],    //在什么时候jwt开始生效
            "exp" => time() + $config['exp'], //token 过期时间
            "data" => [           //记录的userid的信息，这里是自已添加上去的，如果有其它信息，可以再添加数组的键值对
                'uid' => $uid,
            ]
        );

        return JWT::encode($payload, $config['salt'], $config['alg']);
    }

    /**
     * 检查token
     *
     * @param Request $request
     */
    public function checkToken(Request $request)
    {
        $token = trim(ltrim($request->header('Authorization'), 'Bearer'));
        JWT::$leeway = 60;//当前时间减去60，把时间留点余地
        $jwt = JWT::decode($token, config('jwt')['salt'], [config('jwt')['alg']]);
        $request->uid = (int)$jwt->data->uid ?? 0;
    }

    /**
     * 刷新token
     *
     * @param Request $request
     * @return Response
     */
    public function refresh(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            return $this->respondWithToken($request->uid);
        });

    }

    /**
     * 响应token
     * @param $uid
     * @return array
     */
    protected function respondWithToken($uid): array
    {
        return [
            'token' => $this->token($uid),
            'expire' => config('jwt')['exp'],
            'type' => 'Bearer'
        ];
    }

    /**
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function captchaCheck(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            $post = $request->post();
            $this->validating($post);
            $this->validate($post, AuthValidate::class . '.' . AuthValidate::SCENE_PWD_CHANGE_CAPTCHA_CHECK);
            if ((new CaptchaService())->check((string)$post['captcha'], $post['key'])) {
                return $this->sendSmsCode($post);
            } else {
                BizException::throwException(10006);
            }
        });
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function smsCodeCheck(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            $data = $request->post();
            $this->validating($data, true);
            $this->checkSmsCode($request);
            return $this->generateResetKeys($data);
        });
    }

    public function resetPassword(Request $request): Response
    {
        return $this->apiResponse(function () use ($request) {
            $post = $request->post();
            $this->validate($post, AuthValidate::class . '.' . AuthValidate::SCENE_PWD_RESET);
            $this->verifyResetKey($post);
            $encrypt = new EncryptionService();
            $member = (new MemberModel())
                ->where(['company_id' => $this->company_id, 'phone' => $encrypt->encrypt($post['phone']), 'area_code' => $encrypt->encrypt($post['area_code'])])
                ->findOrEmpty()->toArray();
            return MemberModel::edit(['password' => $post['password']], $member['id']);
        });

    }

    /**
     * hK9qhp24FuYIHX9BkxKHQZN+sUIEw94xRSiD4jlXVW2186mH8MD67XuX56iX9k+T
     * @param $data
     * @return false|string
     */
    private function generateResetKeys($data)
    {
        $key = CK::RESET_KEY . $data['area_code'] . '_' . $data['phone'];
        $str = (new EncryptionService())->encrypt(md5($data['area_code'] . $data['phone'] . $data['sms_code']));
        Redis::setex($key, CN::ONE_HOUR, $str);
        return $str;
    }

    /**
     * @param $data
     */
    private function verifyResetKey($data)
    {
        $key = CK::RESET_KEY . $data['area_code'] . '_' . $data['phone'];
        if (Redis::exists($key)) {
            Redis::del($key);
        } else {
            BizException::throwException(10008);
        }
    }

    /**
     * @param $data
     * @return int
     * @throws \Exception
     */
    private function sendSmsCode($data)
    {
        $code = random_int(100000, 999999);
        $key = CK::LOGIN_CODE . $data['area_code'] . '_' . $data['phone'];

        if (Redis::ttl($key) > self::CODE_EXPIRE * 60 - self::CODE_FREQ) {
            BizException::throwException(10002);
        }

        // Todo 此处发信息
        event('SendSms', ['area_code' => $data['area_code'], 'phone' => $data['phone'], 'msg' => '【大航假期】您的驗證碼為:' . $code . ',' . self::CODE_EXPIRE . '分鐘內有效。']);

        Redis::setex($key, self::CODE_EXPIRE * 60, $code);
        return $code;
    }

}