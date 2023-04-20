<?php declare(strict_types=1);

namespace app\api\controller\v1;


use app\api\controller\ApiBaseController;
use app\api\Request;
use app\api\service\captcha\CaptchaService;
use app\api\service\payment\BasePayment;
use app\api\service\CommonService;
use app\api\service\UploaderService;
use comm\constant\CN;
use comm\service\EncryptionService;

class Index extends ApiBaseController
{
    public function index()
    {
//        $ret = (new EncryptionService())->encrypt('18588733132');
//        $ret = (new EncryptionService())->encrypt('15728539732');
//        dd($ret);
        return $this->response->item('WELCOME!');
    }

    /**
     * @return mixed
     */
    public function branch()
    {
        return $this->apiResponse(function () {
            return CommonService::getBranches($this->company_id);
        });
    }

    /**
     * paymentType
     * @return mixed
     */
    public function paymentType()
    {
        return $this->apiResponse(function () {
            return CommonService::paymentType($this->company_id);
        });
    }

    /**
     * @return mixed
     */
    public function contact()
    {
        return $this->cache(CN::ONE_DAY)->apiResponse(function () {
            return [
                'tips_whatsapp' => env('config.whatsapp', '62844992'),
                'tips_tel_phone' => env('config.telphone', '21339468'),
            ];
        });
    }

    /**
     * @return mixed
     */
    public function credentials()
    {
        return $this->cache(CN::ONE_DAY)->apiResponse(function () {
            return array_values(config('system.credentials'));
        });
    }

    /**
     * @return mixed
     */
    public function currency()
    {
        return $this->cache(CN::ONE_DAY)->apiResponse(function () {
            $dd = config('system.currency');
            $ret = [];
            foreach ($dd as $d) {
                array_push($ret, $d);
            }
            return $ret;
        });
    }

    /**
     * @return mixed
     */
    public function traveler_type()
    {
        return $this->cache(CN::ONE_DAY)->apiResponse(function () {
            return config('system.traveler_type');
        });
    }

    /**
     * 图形验证码
     */
    public function captcha()
    {
        return $this->apiResponse(function () {
            return (new CaptchaService())->create();
        });
    }

    /**
     * 支付方式
     */
    public function payType()
    {
        $client = $this->device_type;
        return $this->apiResponse(function () use ($client) {
            return BasePayment::types($client);
        });
    }

    public function offLinePayTypes()
    {
        return $this->apiResponse(function () {
            return BasePayment::offLinePayTypes();
        });
    }

    public function version()
    {
        $get = $this->request->get();
        if ($this->validate($get, ['version' => 'require'])) {
            return $this->apiResponse(function () use ($get) {
                return CommonService::getLastVersion($this->company_id, $this->device_type, $get['version']);
            });
        }
    }

    /**
     * @param Request $request
     * @return array|\think\response\Json
     * @throws \Exception
     */
    public function upload(Request $request)
    {
        $res = (new UploaderService())->upload($request);
        return $this->apiResponse(function () use ($res) {
            return $res;
        });
    }

    public function termsGroup()
    {
        $get = $this->request->get();
        if ($this->validate($get, ['product_id' => 'require'])) {
            return $this->apiResponse(function () use ($get) {
                return CommonService::getTermsGroup($get['product_id']);
            });
        }
    }

    public function termsIndependent()
    {
        return $this->apiResponse(function () {
            return CommonService::getTermsIndependent();
        });
    }
}