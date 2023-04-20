<?php


namespace app\api\controller\v1;


use app\api\controller\ApiBaseController;
use app\api\service\MessageService;
use app\api\validate\MessageValidator;
use think\App;
use think\facade\Log;

class Message extends ApiBaseController
{
    protected $service;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->service = new MessageService((int)$this->company_id, (int)$this->request->uid, (int)$app->request->header('Device', 3));

    }

    /**
     * @return array|\think\response\Json
     */
    public function message_system_count()
    {
        return $this->apiResponse(function () {
            return $this->service->getSystemMessageCount();

        });
    }

    /**
     * @return array|\think\response\Json
     */
    public function message_system_read()
    {
        $params = $this->request->post();
        Log::write('message_system_read:' . json_encode($params));
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, MessageValidator::class . '.' . MessageValidator::PUSH_MESSAGE_READ);
            return $this->service->readSystemMessage($params);

        });
    }

    /**
     * @return array|\think\response\Json
     */
    public function message_system()
    {
        $params = $this->request->get();
        return $this->apiResponse(function () use ($params) {
            $this->validate($params, MessageValidator::class . '.' . MessageValidator::PUSH_MESSAGE);
            $params['status'] = isset($params['status']) ? $params['status'] : 2;
            return $this->service->getSystemMessage($params);

        });
    }

    /**
     * @return array|\think\response\Json
     */
    public function message_system_clear()
    {
        return $this->apiResponse(function () {
            return $this->service->clearSystemMessage();

        });
    }

    /**
     * @return array|\think\response\Json
     */
    public function message_system_delete()
    {
        return $this->apiResponse(function () {
            return $this->service->deleteSystemMessage();
        });
    }

}