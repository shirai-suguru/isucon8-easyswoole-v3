<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2019-01-01
 * Time: 20:06
 */

return [
    'SERVER_NAME' => "EasySwoole",
    'MAIN_SERVER' => [
        'LISTEN_ADDRESS' => '0.0.0.0',
        'PORT' => 9501,
        'SERVER_TYPE' => EASYSWOOLE_WEB_SERVER, // EASYSWOOLE_SERVER  EASYSWOOLE_WEB_SERVER EASYSWOOLE_WEB_SOCKET_SERVER
        'SOCK_TYPE' => SWOOLE_TCP,
        'RUN_MODEL' => SWOOLE_PROCESS,
        'SETTING' => [
            'worker_num' => 8,
            'max_request' => 5000,
            'task_worker_num' => 8,
            'task_max_request' => 1000,
            'document_root' => EASYSWOOLE_ROOT.'/Static',
            'enable_static_handler' => true,
        ],
    ],
    'TEMP_DIR' => null,
    'LOG_DIR' => null,
    'CONSOLE' => [
        'ENABLE' => false,
        'LISTEN_ADDRESS' => '127.0.0.1',
        'HOST' => '127.0.0.1',
        'PORT' => 9500,
        'USER' => 'root',
        'PASSWORD' =>'123456'
    ],
    'FAST_CACHE' => [
        'PROCESS_NUM' => 1,
        'BACKLOG' => 256,
    ],
    'DISPLAY_ERROR' => true,
    /*################ MYSQL CONFIG ##################*/
    'MYSQL'         => [
        'host'          => '127.0.0.1',
        'port'          => '3306',
        'user'          => 'isucon',
        'timeout'       => '5',
        'charset'       => 'utf8mb4',
        'password'      => 'isucon',
        'database'      => 'torb',
        'POOL_MAX_NUM'  => '10',
        'POOL_TIME_OUT' => '0.1'
    ],
];
