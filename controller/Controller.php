<?php declare (strict_types=1);

namespace app\api\controller;

use app\api\lib\BizException;
use comm\model\CompanyModel;
use comm\traits\ApiHelpers;
use think\App;
use think\exception\ValidateException;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class Controller
{

    use ApiHelpers;

    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 访问设备类型
     * @var int
     */
    protected $device_type = 0;

    /**
     * 访问的资源公司
     * @var int
     */
    protected $company_id = 0;
    protected $app_identity = 0;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param App $app 应用对象
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {
        $this->device_type = trim($this->request->header('Device', '3'));
        $this->app_identity = trim($this->request->header('appIdentity', 'bigline'));
        $company = CompanyModel::where(['app_identity' => $this->app_identity])->field('id')->findOrEmpty()->toArray();
        $this->company_id = empty($company) ? 1 : $company['id'];
    }

    /**
     * 验证数据
     * @access protected
     * @param array $data 数据
     * @param string|array $validate 验证器名或者验证规则数组
     * @param array $message 提示信息
     * @param bool $batch 是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        try {
            if (is_array($validate)) {
                $v = new Validate();
                $v->rule($validate);
            } else {
                if (strpos($validate, '.')) {
                    // 支持场景
                    [$validate, $scene] = explode('.', $validate);
                }
                $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
                $v = new $class();

                if (!empty($scene)) {
                    $v->scene($scene);
                }
            }

            $v->message($message);

            // 是否批量验证
            if ($batch || $this->batchValidate) {
                $v->batch(true);
            }

            return $v->failException(true)->check($data);

        } catch (\Exception $e) {
            BizException::throwException(9005, $e->getMessage());
        }

    }

}
