<?php
define('APP_DB_ENGINE','innodb');
define('APP_DB_DATABASE','cerb');
define('APP_DB_PCONNECT',false);

define('APP_DB_HOST','192.168.1.20');
define('APP_DB_USER','cerb');
define('APP_DB_PASS','secret_password');

define('APP_DB_SLAVE_HOST','');
define('APP_DB_SLAVE_USER','');
define('APP_DB_SLAVE_PASS','');

define('LANG_CHARSET_CODE','utf-8');
define('DB_CHARSET_CODE','utf8');

//@ini_set('memory_limit', '64M');

/****************************************************************************
 * [JAS]: Don't change the following unless you know what you're doing!
 ***************************************************************************/
define('APP_DEFAULT_CONTROLLER','core.controller.page');
define('APP_DB_PREFIX','cerb');
define('APP_PATH',dirname(__FILE__));
define('APP_STORAGE_PATH',APP_PATH . '/storage');
define('APP_TEMP_PATH',APP_STORAGE_PATH . '/tmp');
define('DEVBLOCKS_PATH',APP_PATH . '/libs/devblocks/');
define('DEVBLOCKS_REWRITE', file_exists(dirname(__FILE__).'/.htaccess'));
define('DEVELOPMENT_MODE', false);
define('AUTHORIZED_IPS_DEFAULTS','172.17.0.1');
define('ONDEMAND_MODE', false);

require_once(DEVBLOCKS_PATH . 'framework.defaults.php');
