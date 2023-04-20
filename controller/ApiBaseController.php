<?php
/**
 * @author      : Kobin
 * @CreateTime  : 2020/9/8 18:10
 * @copyright Copyright (c) Bigline Group
 */

namespace app\api\controller;

use app\api\lib\BizException;
use app\api\lib\DCache;
use comm\constant\CN;
use think\facade\Log;
use think\response\Json;

class ApiBaseController extends Controller
{
    const ERR_NO_SYS = 9000;

    //接口缓存
    private bool $openApiCache = false;
    //缓存时间
    private int $apiCacheTime = 60;

    use DCache;

    protected function initialize()
    {
        parent::initialize();
        error_reporting(E_ALL ^ E_NOTICE); //错误等级
        //初始化配置参数，用于在模板中使用
    }

    /**
     * 公用接口返回
     * @param $func
     * @param int $code
     * @return array
     */
    public function apiResponse($func, $code = 200): Json
    {
        $response = [
            'code' => $code,
            'message' => '',
            'data' => null
        ];
        try {
            $this->checkFuncCallable($func);
            $data = $this->openApiCache ? static::cacheResult($func, $this->apiCacheTime) : call_user_func($func);
            if (isset($data['code']) && isset($data['data'])) {
                $code = $data['code'];
                $data = $data['data'];
            }
            $response['code'] = $code;
            $response['message'] = '请求成功';
            $response['data'] = empty($data) ? null : $data;
        } catch (BizException $e) {
//            Log::error("业务异常:" . $e->getMessage());
            $response['code'] = $e->getCode();
            $response['message'] = $e->getMessage();
        } catch (\Exception $e) {
            Log::error("系统异常:" . $e->getFile() . $e->getLine() . $e->getMessage());
            $response['code'] = self::ERR_NO_SYS;
            $response['message'] = '系统开小差了~:' . $e->getMessage();
        }
        return json($response);
    }

    /**
     * @param int $time
     * @return ApiBaseController
     */
    public function cache(int $time = CN::ONE_MINUTE): self
    {
        $this->openApiCache = true;
        $this->apiCacheTime = intval($time);
        return $this;
    }

    /**
     * 检查是否可以用
     *
     * @param \Closure $func
     */
    protected function checkFuncCallable(\Closure $func)
    {
        if (!is_callable($func)) {
            throw new \InvalidArgumentException("不是可执行的方法");
        }
    }
}