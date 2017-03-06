<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2017 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\RouteNotFoundException;

/**
 * App 应用管理
 * @author  liu21st <liu21st@gmail.com>
 */
class App extends Container
{
    const VERSION = '5.1.0alpha';

    /**
     * @var string 当前模块路径
     */
    protected $modulePath;

    /**
     * @var bool 应用调试模式
     */
    protected $debug = true;
    protected $beginTime;
    protected $beginMem;

    /**
     * @var string 应用类库命名空间
     */
    protected $namespace = 'app';

    /**
     * @var bool 应用类库后缀
     */
    protected $suffix = false;

    /**
     * @var bool 应用路由检测
     */
    protected $routeCheck;

    /**
     * @var bool 严格路由检测
     */
    protected $routeMust;
    protected $appPath;
    protected $thinkPath;
    protected $rootPath;
    protected $runtimePath;
    protected $configPath;
    protected $configExt;
    protected $dispatch;
    protected $file = [];
    protected $config;
    protected $request;
    protected $hook;
    protected $lang;
    protected $route;

    public function __construct($appPath = '', Config $config, Request $request)
    {
        $this->beginTime   = microtime(true);
        $this->beginMem    = memory_get_usage();
        $this->thinkPath   = __DIR__ . '/../../';
        $this->appPath     = $appPath ?: dirname($_SERVER['SCRIPT_FILENAME']) . DIRECTORY_SEPARATOR;
        $this->config      = $config;
        $this->request     = $request;
        $this->hook        = Facade::make('hook');
        $this->lang        = Facade::make('lang');
        $this->route       = Facade::make('route');
        $this->rootPath    = $this->config('root_path') ?: dirname(realpath($this->appPath)) . DIRECTORY_SEPARATOR;
        $this->runtimePath = $this->config('runtime_path') ?: $this->rootPath . 'runtime' . DIRECTORY_SEPARATOR;
        $this->configPath  = $this->config('config_path') ?: $this->appPath;
        $this->configExt   = $this->config('config_ext') ?: '.php';
        // 初始化应用
        $this->initialize();
    }

    public function version()
    {
        return static::VERSION;
    }

    public function isDebug()
    {
        return $this->debug;
    }

    public function getModulePath()
    {
        return $this->modulePath;
    }

    public function setModulePath($path)
    {
        $this->modulePath = $path;
    }

    public function getRootPath()
    {
        return $this->rootPath;
    }

    public function getAppPath()
    {
        return $this->appPath;
    }

    public function getRuntimePath()
    {
        return $this->runtimePath;
    }

    public function getThinkPath()
    {
        return $this->thinkPath;
    }

    public function getConfigPath()
    {
        return $this->configPath;
    }

    public function getConfigExt()
    {
        return $this->configExt;
    }

    public function getNamespace()
    {
        return $this->namespace;
    }

    public function getSuffix()
    {
        return $this->suffix;
    }

    public function getBeginTime()
    {
        return $this->beginTime;
    }

    public function getBeginMem()
    {
        return $this->beginMem;
    }

    /**
     * 执行应用程序
     * @access public
     * @return Response
     * @throws Exception
     */
    public function run()
    {
        try {
            if (defined('BIND_MODULE')) {
                // 模块/控制器绑定
                BIND_MODULE && $this->route->bind(BIND_MODULE);
            } elseif ($this->config('auto_bind_module')) {
                // 入口自动绑定
                $name = pathinfo($this->request->baseFile(), PATHINFO_FILENAME);
                if ($name && 'index' != $name && is_dir($this->appPath . $name)) {
                    $this->route->bind($name);
                }
            }

            $this->request->filter($this->config('default_filter'));

            if ($this->config('lang_switch_on')) {
                // 开启多语言机制 检测当前语言
                $this->lang->detect();
            } else {
                // 读取默认语言
                $this->lang->range($this->config('default_lang'));
            }
            $this->request->langset($this->lang->range());
            // 加载系统语言包
            $this->lang->load([
                $this->thinkPath . 'lang/' . $this->request->langset() . '.php',
                $this->appPath . 'lang/' . $this->request->langset() . '.php',
            ]);

            // 获取应用调度信息
            $dispatch = $this->dispatch;
            if (empty($dispatch)) {
                // 进行URL路由检测
                $dispatch = $this->routeCheck($this->request);
            }
            // 记录当前调度信息
            $this->request->dispatch($dispatch);

            // 记录路由和请求信息
            if ($this->debug) {
                $this->log('[ ROUTE ] ' . var_export($dispatch, true));
                $this->log('[ HEADER ] ' . var_export($this->request->header(), true));
                $this->log('[ PARAM ] ' . var_export($this->request->param(), true));
            }

            // 监听app_begin
            $this->hook->listen('app_begin', $dispatch);
            // 请求缓存检查
            $this->request->cache($this->config('request_cache'), $this->config('request_cache_expire'), $this->config('request_cache_except'));

            $data = $this->exec($dispatch);

        } catch (HttpResponseException $exception) {
            $data = $exception->getResponse();
        }

        // 输出数据到客户端
        if ($data instanceof Response) {
            $response = $data;
        } elseif (!is_null($data)) {
            // 默认自动识别响应输出类型
            $isAjax   = $this->request->isAjax();
            $type     = $isAjax ? $this->config('default_ajax_return') : $this->config('default_return_type');
            $response = Response::create($data, $type);
        } else {
            $response = Response::create();
        }

        // 监听app_end
        $this->hook->listen('app_end', $response);

        return $response;
    }

    public function exec($dispatch)
    {
        switch ($dispatch['type']) {
            case 'redirect':
                // 执行重定向跳转
                $data = Response::create($dispatch['url'], 'redirect')->code($dispatch['status']);
                break;
            case 'module':
                // 模块/控制器/操作
                $data = $this->module($dispatch['module'], isset($dispatch['convert']) ? $dispatch['convert'] : null);
                break;
            case 'controller':
                // 执行控制器操作
                $vars = array_merge($this->request->param(), $dispatch['var']);
                $data = Loader::action($dispatch['controller'], $vars, $this->config('url_controller_layer'), $this->config('controller_suffix'));
                break;
            case 'method':
                // 执行回调方法
                $vars = array_merge($this->request->param(), $dispatch['var']);
                $data = Container::getInstance()->invokeMethod($dispatch['method'], $vars);
                break;
            case 'function':
                // 执行闭包
                $data = Container::getInstance()->invokeFunction($dispatch['function']);
                break;
            case 'response':
                $data = $dispatch['response'];
                break;
            default:
                throw new \InvalidArgumentException('dispatch type not support');
        }
        return $data;
    }

    /**
     * 设置当前请求的调度信息
     * @access public
     * @param array|string  $dispatch 调度信息
     * @param string        $type 调度类型
     * @return void
     */
    public function dispatch($dispatch, $type = 'module')
    {
        $this->dispatch = ['type' => $type, $type => $dispatch];
    }

    /**
     * 记录调试信息
     * @param mixed  $msg  调试信息
     * @param string $type 信息类型
     * @return void
     */
    public function log($log, $type = 'info')
    {
        $this->debug && Facade::make('log')->record($log, $type);
    }

    /**
     * 获取配置参数 为空则获取所有配置
     * @param string    $name 配置参数名（支持二级配置 .号分割）
     * @return mixed
     */
    public function config($name = '')
    {
        return $this->config->get($name);
    }

    /**
     * 执行模块
     * @access public
     * @param array $result 模块/控制器/操作
     * @param bool  $convert 是否自动转换控制器和操作名
     * @return mixed
     */
    public function module($result, $convert = null)
    {
        if (is_string($result)) {
            $result = explode('/', $result);
        }

        if ($this->config('app_multi_module')) {
            // 多模块部署
            $module    = strip_tags(strtolower($result[0] ?: $this->config('default_module')));
            $bind      = $this->route->getBind('module');
            $available = false;
            if ($bind) {
                // 绑定模块
                list($bindModule) = explode('/', $bind);
                if (empty($result[0])) {
                    $module    = $bindModule;
                    $available = true;
                } elseif ($module == $bindModule) {
                    $available = true;
                }
            } elseif (!in_array($module, $this->config('deny_module_list')) && is_dir($this->appPath . $module)) {
                $available = true;
            }

            // 模块初始化
            if ($module && $available) {
                // 初始化模块
                $this->request->module($module);
                $this->init($module);
                // 模块请求缓存检查
                $this->request->cache($this->config('request_cache'), $this->config('request_cache_expire'), $this->config('request_cache_except'));
            } else {
                throw new HttpException(404, 'module not exists:' . $module);
            }
        } else {
            // 单一模块部署
            $module = '';
            $this->request->module($module);
        }
        // 当前模块路径
        $this->modulePath = $this->appPath . ($module ? $module . '/' : '');

        // 是否自动转换控制器和操作名
        $convert = is_bool($convert) ? $convert : $this->config('url_convert');
        // 获取控制器名
        $controller = strip_tags($result[1] ?: $this->config('default_controller'));
        $controller = $convert ? strtolower($controller) : $controller;

        // 获取操作名
        $actionName = strip_tags($result[2] ?: $this->config('default_action'));
        $actionName = $convert ? strtolower($actionName) : $actionName;

        // 设置当前请求的控制器、操作
        $this->request->controller(Loader::parseName($controller, 1))->action($actionName);

        // 监听module_init
        $this->hook->listen('module_init', $this->request);

        $instance = Loader::controller($controller, $this->config('url_controller_layer'), $this->config('controller_suffix'), $this->config('empty_controller'));
        if (is_null($instance)) {
            throw new HttpException(404, 'controller not exists:' . Loader::parseName($controller, 1));
        }
        // 获取当前操作名
        $action = $actionName . $this->config('action_suffix');

        if (is_callable([$instance, $action])) {
            // 执行操作方法
            $call = [$instance, $action];
            // 自动获取请求变量
            $vars = $this->Config('url_param_type')
            ? $this->request->route()
            : $this->request->param();
        } elseif (is_callable([$instance, '_empty'])) {
            // 空操作
            $call = [$instance, '_empty'];
            $vars = [$actionName];
        } else {
            // 操作不存在
            throw new HttpException(404, 'method not exists:' . get_class($instance) . '->' . $action . '()');
        }

        $this->hook->listen('action_begin', $call);

        return Container::getInstance()->invokeMethod($call, $vars);
    }

    /**
     * 初始化应用
     */
    public function initialize()
    {
        // 加载环境变量配置文件
        if (is_file($this->rootPath . '.env')) {
            $env = parse_ini_file($this->rootPath . '.env', true);
            foreach ($env as $key => $val) {
                $name = $this->config('env_prefix') . strtoupper($key);
                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        $item = $name . '_' . strtoupper($k);
                        putenv("$item=$v");
                    }
                } else {
                    putenv("$name=$val");
                }
            }
        }
        // 初始化应用
        $this->init();
        $this->suffix = $this->config('class_suffix');

        // 应用调试模式
        $this->debug = Env::get('app_debug', $this->config('app_debug'));
        if (!$this->debug) {
            ini_set('display_errors', 'Off');
        } elseif (PHP_SAPI != 'cli') {
            //重新申请一块比较大的buffer
            if (ob_get_level() > 0) {
                $output = ob_get_clean();
            }
            ob_start();
            if (!empty($output)) {
                echo $output;
            }
        }

        // 注册应用命名空间
        $this->namespace = $this->config('app_namespace');
        Loader::addNamespace($this->config('app_namespace'), $this->appPath);
        if (!empty($this->config('root_namespace'))) {
            Loader::addNamespace($this->config('root_namespace'));
        }

        // 加载类库映射文件
        if (is_file($this->runtimePath . 'classmap.php')) {
            Loader::addClassMap(__include_file($this->runtimePath . 'classmap.php'));
        }

        // Composer自动加载支持
        if (is_dir($this->rootPath . 'vendor/composer')) {
            Loader::registerComposerLoader($this->rootPath . 'vendor/composer/');
        }

        // 自动加载extend目录
        Loader::addAutoLoadDir($this->rootPath . 'extend');
        // 加载额外文件
        if (!empty($this->config('extra_file_list'))) {
            foreach ($this->config('extra_file_list') as $file) {
                $file = strpos($file, '.') ? $file : $this->appPath . $file . '.php';
                if (is_file($file) && !isset($this->file[$file])) {
                    include $file;
                    $this->file[$file] = true;
                }
            }
        }

        // 设置系统时区
        date_default_timezone_set($this->config('default_timezone'));

        // 监听app_init
        $this->hook->listen('app_init');

    }

    /**
     * 初始化应用或模块
     * @access public
     * @param string $module 模块名
     * @return void
     */
    private function init($module = '')
    {
        // 定位模块目录
        $module = $module ? $module . DIRECTORY_SEPARATOR : '';

        // 加载初始化文件
        if (is_file($this->appPath . $module . 'init.php')) {
            include $this->appPath . $module . 'init.php';
        } elseif (is_file($this->runtimePath . $module . 'init.php')) {
            include $this->runtimePath . $module . 'init.php';
        } else {
            $path = $this->appPath . $module;
            // 加载模块配置
            $this->config->load($this->configPath . $module . 'config' . $this->configExt);
            // 读取数据库配置文件
            $filename = $this->configPath . $module . 'database' . $this->configExt;
            $this->config->load($filename, 'database');
            // 读取扩展配置文件
            if (is_dir($this->configPath . $module . 'extra')) {
                $dir   = $this->configPath . $module . 'extra';
                $files = scandir($dir);
                foreach ($files as $file) {
                    if (strpos($file, $this->configExt)) {
                        $filename = $dir . DIRECTORY_SEPARATOR . $file;
                        $this->config->load($filename, pathinfo($file, PATHINFO_FILENAME));
                    }
                }
            }

            // 加载应用状态配置
            if ($this->config('app_status')) {
                $this->config->load($this->configPath . $module . $this->config('app_status') . $this->configExt);
            }

            // 加载行为扩展文件
            if (is_file($this->configPath . $module . 'tags.php')) {
                $this->hook->import(include $this->configPath . $module . 'tags.php');
            }

            // 加载公共文件
            if (is_file($path . 'common.php')) {
                include $path . 'common.php';
            }

            // 加载当前模块语言包
            if ($module) {
                $this->lang->load($path . 'lang/' . $this->request->langset() . '.php');
            }
        }

    }

    /**
     * URL路由检测（根据PATH_INFO)
     * @access public
     * @return array
     * @throws \think\Exception
     */
    public function routeCheck()
    {
        $path   = $this->request->path();
        $depr   = $this->config('pathinfo_depr');
        $result = false;

        // 路由检测
        $check = !is_null($this->routeCheck) ? $this->routeCheck : $this->config('url_route_on');
        if ($check) {
            // 开启路由
            if (is_file($this->runtimePath . 'route.php')) {
                // 读取路由缓存
                $rules = include $this->runtimePath . 'route.php';
                if (is_array($rules)) {
                    $this->route->rules($rules);
                }
            } else {
                foreach ($this->config('route_config_file') as $file) {
                    if (is_file($this->configPath . $file . $this->configExt)) {
                        // 导入路由配置
                        $rules = include $this->configPath . $file . $this->configExt;
                        if (is_array($rules)) {
                            $this->route->import($rules);
                        }
                    }
                }
            }

            // 路由检测（根据路由定义返回不同的URL调度）
            $result = $this->route->check($this->request, $path, $depr, $this->config('url_domain_deploy'));
            $must   = !is_null($this->routeMust) ? $this->routeMust : $this->config('url_route_must');

            if ($must && false === $result) {
                // 路由无效
                throw new RouteNotFoundException();
            }
        }
        if (false === $result) {
            // 路由无效 解析模块/控制器/操作/参数... 支持控制器自动搜索
            $result = $this->route->parseUrl($path, $depr, $this->config('controller_auto_search'));
        }
        return $result;
    }

    /**
     * 设置应用的路由检测机制
     * @access public
     * @param  bool $route 是否需要检测路由
     * @param  bool $must  是否强制检测路由
     * @return void
     */
    public function route($route, $must = false)
    {
        $this->routeCheck = $route;
        $this->routeMust  = $must;
    }
}
