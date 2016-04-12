<?php

namespace T4\Mvc;

use T4\Console\TRunCommand;
use T4\Core\Exception;
use T4\Core\ISingleton;
use T4\Core\Session;
use T4\Core\Std;
use T4\Core\TSingleton;
use T4\Core\TStdGetSet;
use T4\Http\E403Exception;
use T4\Http\E404Exception;
use T4\Http\Request;
use T4\Threads\Helpers;

/**
 * Class Application
 * @package T4\Mvc
 *
 * @property string $path
 * @property string $routeConfigPath
 *
 * @property \T4\Core\Config $config
 * @property \T4\Mvc\IRouter $router
 * @property \T4\Dbal\Connections|\T4\Dbal\Connection[] $db
 * @property \T4\Http\Request $request
 * @property \App\Models\User $user
 * @property \T4\Mvc\Module[] $modules
 * @property \T4\Mvc\AssetsManager $assets
 * @property \T4\Core\Flash $flash
 */
class Application
    implements
        ISingleton,
        IApplication
{
    use
        TStdGetSet,
        TSingleton,
        TApplicationPaths,
        TApplicationMagic;

    use TRunCommand;

    /**
     * @var \T4\Core\Std
     */
    public $extensions;

    protected function initExtensions()
    {
        $this->extensions = new Std;
        if (isset($this->config->extensions)) {
            foreach ($this->config->extensions as $extension => $options) {

                if (!empty($options->class)) {
                    $extensionClassName = $options->class;
                } else {
                    $extensionClassName = 'Extensions\\' . ucfirst($extension) . '\\Extension';
                    if (class_exists('\\App\\' . $extensionClassName)) {
                        $extensionClassName = '\\App\\' . $extensionClassName;
                    } else {
                        $extensionClassName = '\\T4\\Mvc\\' . $extensionClassName;
                    }
                }

                $this->extensions->{$extension} = new $extensionClassName($options);
                if (!isset($options->autoload) || true == $options->autoload) {
                    $this->extensions->{$extension}->init();
                }
            }
        }
    }

    /**
     * Запуск веб-приложения
     * и формирование ответа
     */
    public function run()
    {
        try {

            Session::init();
            $this->initExtensions();
            $this->runRequest($this->request);

        } catch (Exception $e) {
            try {
                if ($e instanceof E404Exception) {
                    header("HTTP/1.0 404 Not Found", true, 404);
                    if (!empty($this->config->errors['404'])) {
                        $this->runRoute($this->config->errors['404']);
                    } else {
                        echo $e->getMessage();
                    }
                } elseif ($e instanceof E403Exception) {
                    header('HTTP/1.0 403 Forbidden', true, 403);
                    if (!empty($this->config->errors['403'])) {
                        $this->runRoute($this->config->errors['403']);
                    } else {
                        echo $e->getMessage();
                    }
                } else {
                    echo $e->getMessage();
                    die;
                }
            } catch (Exception $e2) {
                echo $e2->getMessage();
                die;
            }
        }
    }
    /**
     * @param \T4\Http\Request $request
     */
    protected function runRequest(Request $request)
    {
        $route = $this->router->parseRequest($request);
        $this->runRoute($route, $route->format);
    }

    /**
     * @param \T4\Mvc\Route|string $route
     * @param string $format
     * @throws ControllerException
     * @throws E403Exception
     * @throws Exception
     */
    public function runRoute($route, $format = 'html')
    {
        if (!($route instanceof Route)) {
            $route = new Route((string)$route);
        }

        $controller = $this->createController($route->module, $route->controller);
        $this->runController($controller, $route->action, $route->params, $format);
    }

    /**
     * @param Controller $controller
     * @param string $action
     * @param array $params
     * @param string $format
     * @throws ControllerException
     * @throws E403Exception
     * @throws E404Exception
     */
    public function runController(Controller $controller, $action, $params = [], $format = 'html')
    {
        $controller->action($action, $params);
        $data = $controller->getData();

        switch ($format) {
            case 'json':
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($data->toArray(), JSON_UNESCAPED_UNICODE);
                die;
            case 'xml':
                header('Content-Type: text/xml; charset=utf-8');
                $controller->view->display($action . '.' . $format, $data);
                break;
            default:
            case 'html':
                header('Content-Type: text/html; charset=utf-8');
                $controller->view->display($action . '.' . $format, $data);
                break;
        }
    }

    /**
     * @param callable $callback
     * @param array $args
     * @throws \T4\Threads\Exception
     * @return int Child process PID
     */
    public function runLater(callable $callback, $args = [])
    {
        return Helpers::run($callback, $args);
    }


    /**
     * Вызов блока
     * @param string $path Внутренний путь до блока
     * @param string $template Шаблон блока
     * @param array $params Параметры, передаваемые блоку
     * @throws \T4\Core\Exception
     * @return string Результат рендера блока
     */
    public function callBlock($path, $template = '', $params = [])
    {
        $route = new Route($path);
        $route->params->merge($params);

        $canonicalPath = $route->toString();

        if (isset($this->config->blocks) && isset($this->config->blocks[$canonicalPath])) {
            $blockOptions = $this->config->blocks[$canonicalPath];
        } else {
            $blockOptions = [];
        }

        $getBlock = function () use ($template, $route) {
            $controller = $this->createController($route->module, $route->controller);
            $controller->action($route->action, $route->params);
            return $controller->view->render(
                $route->action . (!empty($template) ? '.' . $template : '') . '.block.html',
                $controller->getData()
            );
        };

        if (!empty($blockOptions['cache'])) {
            $cache = \T4\Cache\Factory::getInstance();
            $key = md5($canonicalPath . serialize($route->params) . $template);
            if (!empty($blockOptions['cache']['time'])) {
                return $cache($key, $getBlock, $blockOptions['cache']['time']);
            } else {
                return $cache($key, $getBlock);
            }
        } else {
            return $getBlock();
        }

    }

    /**
     * Возвращает экземпляр контроллера
     * @param string $module
     * @param string $controller
     * @throws \T4\Core\Exception
     * @return \T4\Mvc\Controller
     */
    public function createController($module, $controller)
    {
        if (!$this->existsController($module ?:  null, $controller))
            throw new Exception('Controller ' . $controller . ' does not exist');

        if (empty($module))
            $controllerClass = '\\App\\Controllers\\' . $controller;
        else
            $controllerClass = '\\App\\Modules\\' . ucfirst($module) . '\\Controllers\\' . ucfirst($controller);

        $controller = new $controllerClass;
        return $controller;
    }

}