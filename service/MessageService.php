<?php


namespace app\api\service;


use app\api\lib\BizException;
use app\api\model\member\MemberMessageModel;
use app\api\model\app\PushMessageModel;

class MessageService extends ApiServiceBase
{
    /**
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSystemMessageCount()
    {
        $ret = [
            'system_count' => 0,
            'personal_count' => 0
        ];
        if ($this->member_id) {
            $this->handleMessageSystemToPersonal();
            $ret['system_count'] = (new MemberMessageModel())->getMessageCount($this->member_id);
        } else {
            $ret['system_count'] = (new PushMessageModel())->getMessageCount();
        }
        return $ret;
    }

    /**
     * @param $params
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSystemMessage($params): array
    {
        if ($this->member_id) {
            $this->handleMessageSystemToPersonal();
            $ret = (new MemberMessageModel())->getMessageList($params, $this->member_id);
        } else {
            $ret = (new PushMessageModel())->getMessageList($params);
        }
        return $ret;
    }

    /**
     * @param int $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function handleMessageSystemToPersonal($id = 0)
    {
        $model = new PushMessageModel();
        if ($id) {
            $pushMessages = $model->getMessageList([], [$id]);
        } else {
            $pushMessages = $model->getMessageList();
        }

        foreach ($pushMessages as $m) {
            $msg = MemberMessageModel::withTrashed()->where(['member_id' => $this->member_id, 'message_id' => $m['id'], 'origin' => MemberMessageModel::ORIGIN_SYSTEM])
                ->findOrEmpty()->toArray();
            if (empty($msg)) {
                $m['message_id'] = $m['id'];
                $m['origin'] = MemberMessageModel::ORIGIN_SYSTEM;
                $m['member_id'] = $this->member_id;
                unset($m['id']);
                MemberMessageModel::create($m);
            }
        }
    }

    /**
     * @return array
     */
    public function clearSystemMessage()
    {
        try {
            MemberMessageModel::update(['status' => 1], ['member_id' => $this->member_id, 'origin' => MemberMessageModel::ORIGIN_SYSTEM]);
            return [];
        } catch (\Exception $exception) {
            BizException::throwException(9000, '未知錯誤。');
        }
    }

    /**
     * @return array
     */
    public function deleteSystemMessage()
    {
        try {
            MemberMessageModel::update(['delete_time' => time()], ['member_id' => $this->member_id, 'origin' => MemberMessageModel::ORIGIN_SYSTEM]);
            return [];
        } catch (\Exception $exception) {
            BizException::throwException(9000, '未知錯誤。');
        }
    }

    /**
     * @param $params
     * @return array
     */
    public function readSystemMessage($params): array
    {
        if ($this->member_id) {
            $this->handleMessageSystemToPersonal($params['id']);
            MemberMessageModel::update(['status' => 1], ['member_id' => $this->member_id, 'message_id' => $params['id']]);
        }
        return [];
    }
}