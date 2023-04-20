<?php
/**
 * Description :
 * Author      : Kobin
 * CreateTime  : 2021/8/16 下午2:41
 */

namespace app\api\controller\v1;


use app\api\controller\ApiBaseController;
use app\api\lib\BizException;
use app\api\service\MemberService;
use app\api\validate\MemberValidator;
use think\App;

class Member extends ApiBaseController
{
    private $service;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->service = new MemberService($this->company_id, $this->request->uid, (int)$app->request->header('Device', 3));
    }

    /**
     * @return mixed
     */
    public function me()
    {
        if ($this->request->user()) {
            return $this->apiResponse(function () {
                return $this->service->me($this->request->user());
            });
        } else {
            BizException::throwException(401);
        }
    }

    /**
     * @return mixed
     */
    public function collection()
    {
        $params = $this->request->get();
        $this->validate($params, MemberValidator::class . '.' . MemberValidator::COLLECTION);
        return $this->apiResponse(function () use ($params) {
            return $this->service->collection($params);
        });
    }

    /**
     * @return mixed
     */
    public function collection_cancel()
    {
        $params = $this->request->post();
        $this->validate($params, MemberValidator::class . '.' . MemberValidator::COLLECTION_CANCEL);
        return $this->apiResponse(function () use ($params) {
            return $this->service->collection_cancel($params);
        });
    }

    /**
     * @return mixed
     */
    public function trace()
    {
        $params = $this->request->get();
        $this->validate($params, MemberValidator::class . '.' . MemberValidator::TRACE);
        return $this->apiResponse(function () use ($params) {
            return $this->service->trace($params);
        });
    }

    public function trace_clear()
    {
        return $this->apiResponse(function () {
            return $this->service->trace_clear();
        });
    }

}