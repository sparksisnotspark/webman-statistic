<?php

namespace Hsk99\WebmanStatistic;

class Middleware implements \Webman\MiddlewareInterface
{
    /**
     * @var array
     */
    public $sqlLogs = [];

    /**
     * @var array
     */
    public $redisLogs = [];

    /**
     * @author HSK
     * @date 2022-06-17 15:37:46
     *
     * @param \Webman\Http\Request $request
     * @param callable $next
     *
     * @return \Webman\Http\Response
     */
    public function process(\Webman\Http\Request $request, callable $next): \Webman\Http\Response
    {
        $startTime = microtime(true);
        $project   = config('plugin.hsk99.statistic.app.project');
        $ip        = $request->getRealIp($safe_mode = true);
        $transfer  = $request->controller . '::' . $request->action;
        if ('::' === $transfer) {
            $transfer = $request->path();
        }

        /**
         * @var \Webman\Http\Response
         */
        $response = $next($request);

        $finishTime = microtime(true);
        $costTime   = $finishTime - $startTime;

        static $initialized;
        if (!$initialized) {
            if (class_exists(\think\facade\Db::class)) {
                \think\facade\Db::listen(function ($sql, $runtime, $master) {
                    if ($sql === 'select 1') {
                        return;
                    }
                    $this->sqlLogs[] = trim($sql) . " [ RunTime:{$runtime}s ]";
                });
            }

            if (class_exists(\Illuminate\Database\Events\QueryExecuted::class)) {
                foreach (config('database.connections', []) as $key => $config) {
                    $driver = $config['driver'] ?? 'mysql';
                    try {
                        \support\Db::connection($key)->listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($key, $driver) {
                            $sql = trim($query->sql);
                            if (strtolower($sql) === 'select 1') {
                                return;
                            }
                            $sql = str_replace("?", "%s", $sql);
                            foreach ($query->bindings as $i => $binding) {
                                if ($binding instanceof \DateTime) {
                                    $query->bindings[$i] = $binding->format("'Y-m-d H:i:s'");
                                } else {
                                    if (is_string($binding)) {
                                        $query->bindings[$i] = "'$binding'";
                                    }
                                }
                            }
                            $log = vsprintf($sql, $query->bindings);
                            $this->sqlLogs[] = "[driver:$driver] [connection:$key] $log [ RunTime:" . ($query->time / 1000) . "s ]";
                        });
                    } catch (\Throwable $e) {
                    }
                }
            }

            if (class_exists(\Illuminate\Redis\Events\CommandExecuted::class)) {
                foreach (config('redis', []) as $key => $config) {
                    if (strpos($key, 'redis-queue') !== false) {
                        continue;
                    }
                    try {
                        \support\Redis::connection($key)->listen(function (\Illuminate\Redis\Events\CommandExecuted $command) use ($key) {
                            $this->redisLogs[] = "[connection:$key] Redis::{$command->command}('" . implode('\', \'', $command->parameters) . "') ({$command->time} ms)\r\n";
                        });
                    } catch (\Throwable $e) {
                    }
                }
            }

            $initialized = true;
        }

        switch (true) {
            case method_exists($response, 'exception') && $exception = $response->exception():
                $body = (string)$exception;
                break;
            case 'application/json' === strtolower($response->getHeader('Content-Type')):
                $body = json_decode($response->rawBody(), true);
                break;
            default:
                $body = 'Non Json data';
                break;
        }

        $code    = $response->getStatusCode();
        $success = $code < 400;
        $details = [
            'time'            => date('Y-m-d H:i:s.', (int)$startTime) . substr($startTime, 11),   // 请求时间（包含毫秒时间）
            'run_time'        => $costTime,                                                        // 运行时长
            'ip'              => $request->getRealIp($safe_mode = true) ?? '',                     // 请求客户端IP
            'url'             => $request->fullUrl() ?? '',                                        // 请求URL
            'method'          => $request->method() ?? '',                                         // 请求方法
            'request_param'   => $request->all() ?? [],                                            // 请求参数
            'request_header'  => $request->header() ?? [],                                         // 请求头
            'cookie'          => $request->cookie() ?? [],                                         // 请求cookie
            'session'         => $request->session()->all() ?? [],                                 // 请求session
            'response_code'   => $response->getStatusCode() ?? '',                                 // 响应码
            'response_header' => $response->getHeaders() ?? [],                                    // 响应头
            'response_body'   => $body,                                                            // 响应数据
            'sql'             => $this->sqlLogs,                                                   // 运行SQL
            'redis'           => $this->redisLogs,                                                 // 运行Redis
        ];
        $this->sqlLogs = [];
        $this->redisLogs = [];

        \Hsk99\WebmanStatistic\Statistic::$transfer .= json_encode([
            'time'     => date('Y-m-d H:i:s.', (int)$startTime) . substr($startTime, 11),
            'project'  => $project,
            'ip'       => $ip,
            'transfer' => $transfer,
            'costTime' => $costTime,
            'success'  => $success ? 1 : 0,
            'code'     => $code,
            'details'  => json_encode($details, 320),
        ], 320) . "\n";

        if (strlen(\Hsk99\WebmanStatistic\Statistic::$transfer) > 1024 * 1024) {
            \Hsk99\WebmanStatistic\Statistic::report();
        }

        return $response;
    }
}