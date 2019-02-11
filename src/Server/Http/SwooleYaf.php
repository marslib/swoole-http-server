<?php
namespace MarsLib\Server\Http;

use MarsLib\Scaffold\Common\Config;

class SwooleYaf extends Base
{

    private $appConfigFile;
    /**
     * Variable  yafAppObj
     * @var
     */
    private $yafAppObj;

    /**
     * Method  setAppConfigIni
     * @desc   ......
     *
     * @param $appConfigIni
     *
     * @return void
     */
    public function setAppConfigIni($appConfigIni)
    {
        if(!is_file($appConfigIni)) {
            trigger_error('Server Config File Not Exist!', E_USER_ERROR);
        }
        $this->appConfigFile = $appConfigIni;
    }

    /**
     * Method  onWorkerStart
     * @desc   worker process start
     *
     * @param \swoole_http_server $serverObj
     * @param                    $workerId
     *
     * @return bool
     */
    public function onWorkerStart(\swoole_http_server $serverObj, $workerId)
    {
        //实例化yaf
        $this->yafAppObj = new Yaf\Application($this->appConfigFile);
        Config::add(Yaf\Application::app()->getConfig()->toArray());
        parent::onWorkerStart($serverObj, $workerId);

        return true;
    }

    /**
     * Method  onRequest
     * @desc   http 请求部分
     *
     * @param swoole_http_request  $request
     * @param swoole_http_response $response
     *
     * @return void
     */
    public function onRequest(\swoole_http_request $request, \swoole_http_response $response)
    {
        //清理环境
        //Yaf\Registry::flush();
        //Yaf\Dispatcher::destoryInstance();
        //注册全局信息
        $this->initRequestParam($request);
        Yaf\Registry::set('SWOOLE_HTTP_REQUEST', $request);
        Yaf\Registry::set('SWOOLE_HTTP_RESPONSE', $response);
        //执行
        ob_start();
        $result = json_encode([
            'code' => CODE_ERR_SYSTEM,
            'message' => '服务器内部错误',
        ], JSON_UNESCAPED_UNICODE);
        try{
            $requestObj = new Yaf\Request\Http($request->server['request_uri']);
            $configArr = Yaf\Application::app()->getConfig()->toArray();
            if(!empty($configArr['application']['baseUri'])) {
                $requestObj->setBaseUri($configArr['application']['baseUri']);
            }
            $this->yafAppObj->bootstrap()->getDispatcher()->dispatch($requestObj);
            $result = ob_get_contents();
            ob_end_clean();
        } catch(Yaf\Exception $e){
            error_report("$e");
        } catch(\Exception $e){
            error_report("$e");
        } catch(\Throwable $e){
            error_report("$e");
        }
        $response->header('Content-Type', 'application/json');
        $response->end($result);
    }

    /**
     * Method  initRequestParam
     * @desc   将请求信息放入全局注册器中
     *
     * @param p_request $request
     *
     * @return bool
     */
    private function initRequestParam(\swoole_http_request $request)
    {
        //将请求的一些环境参数放入全局变量桶中
        $server = isset($request->server) ? $request->server : [];
        $header = isset($request->header) ? $request->header : [];
        $get = isset($request->get) ? $request->get : [];
        $post = isset($request->post) ? $request->post : [];
        $cookie = isset($request->cookie) ? $request->cookie : [];
        $files = isset($request->files) ? $request->files : [];
        Yaf\Registry::set('REQUEST_SERVER', $server);
        Yaf\Registry::set('REQUEST_HEADER', $header);
        Yaf\Registry::set('REQUEST_GET', $get);
        Yaf\Registry::set('REQUEST_POST', $post);
        Yaf\Registry::set('REQUEST_COOKIE', $cookie);
        Yaf\Registry::set('REQUEST_FILES', $files);
        Yaf\Registry::set('REQUEST_RAW_CONTENT', $request->rawContent());

        return true;
    }
}
