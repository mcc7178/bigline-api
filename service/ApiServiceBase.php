<?php
/**
 * Description :
 * Author      : Kobin
 * CreateTime  : 2021/8/12 下午4:07
 */

namespace app\api\service;


class ApiServiceBase
{
    protected int $company_id = 0;
    protected int $member_id = 0;
    protected int $device_id = 0;

    public function __construct(int $company_id, int $member_id, int $device_id)
    {
        $this->company_id = $company_id;
        $this->member_id = $member_id;
        $this->device_id = $device_id;
    }

}