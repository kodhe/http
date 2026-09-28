<?php

declare(strict_types=1);

/**
 * Bootstrap for the HTTP package unit tests.
 *
 * Loads composer autoloader and provides minimal shims so classes that
 * touch legacy CodeIgniter globals can be unit-tested in isolation.
 */
$autoloads = [
    __DIR__ . '/../../../vendor/autoload.php', // package-local checkout
    __DIR__ . '/../../../../vendor/autoload.php', // installed as a dependency
];

$loaded = false;
foreach ($autoloads as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $loaded = true;
        break;
    }
}

/**
 * Fallback PSR-4 autoloader so the test suite can run without a composer
 * install. Maps the Kodhe\Framework namespace onto the monorepo source dirs.
 */
if (!$loaded) {
    spl_autoload_register(function ($class) {
        static $prefixes = [
            'Kodhe\\Framework\\Http\\'       => __DIR__ . '/../../src/',
            'Kodhe\\Framework\\Exceptions\\' => __DIR__ . '/../../../framework/src/Exceptions/',
            'Kodhe\\Framework\\Cache\\'      => __DIR__ . '/../../../cache/src/',
            'Kodhe\\Framework\\'             => __DIR__ . '/../../../framework/src/',
        ];

        foreach ($prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    });
}

/**
 * Legacy CodeIgniter constant used by Router/RoutingManager/RouteCollection
 * default configuration (e.g. 'cache_routes' => ENVIRONMENT === 'production').
 * Define it here so routing classes can be loaded in isolation during tests.
 */
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'testing');
}

/**
 * Legacy CI global constant used by URI/Utf8 (referenced unqualified inside
 * the Kodhe\Framework\Support\Legacy namespace, so it resolves as a global).
 */
if (!defined('UTF8_ENABLED')) {
    define('UTF8_ENABLED', false);
}

/**
 * Legacy CodeIgniter path constants used by Config/URI/etc. Point it to a
 * temporary directory so classes that reference APPPATH can be constructed
 * in isolation during unit tests.
 */
if (!defined('APPPATH')) {
    define('APPPATH', sys_get_temp_dir() . '/kodhe-http-test-app-' . getmypid() . '/');
}

if (!is_dir(APPPATH)) {
    @mkdir(APPPATH, 0777, true);
}

if (!defined('STORAGEPATH')) {
    define('STORAGEPATH', APPPATH . 'storage/');
}

if (!is_dir(STORAGEPATH . 'cache')) {
    @mkdir(STORAGEPATH . 'cache', 0777, true);
}

/**
 * Provide a minimal APPPATH/config/config.php so the legacy get_config()
 * bootstrap (called when Config/URI are instantiated, e.g. from
 * Router::__construct during ControllerExecutor::execute()) succeeds in
 * isolated tests instead of throwing ConfigurationException ("route not
 * found" style hard failures).
 */
if (!is_file(APPPATH . 'config/config.php')) {
    @mkdir(APPPATH . 'config', 0777, true);
    @file_put_contents(
        APPPATH . 'config/config.php',
        "<?php\nreturn [\n    'base_url' => 'http://localhost/',\n    'index_page' => '',\n    'uri_protocol' => 'REQUEST_URI',\n    'permitted_uri_chars' => 'a-z 0-9~%.:_\\-',\n    'enable_query_strings' => false,\n];\n"
    );
}

/**
 * Load the framework's support helpers FIRST. This defines resolve_path()
 * (plus app_path_in() and friends) which the legacy CI common functions
 * (get_config(), load_class(), ...) depend on. In a real application these
 * files are autoloaded via composer "files"; in isolated unit tests we must
 * require them manually, otherwise instantiating Router -> URI -> Config
 * fails with "Call to undefined function resolve_path()" and closure routes
 * appear broken (fatal instead of executing).
 */
$frameworkHelpers = __DIR__ . '/../../../framework/src/Support/Helpers.php';
if (is_file($frameworkHelpers)) {
    require_once $frameworkHelpers;
}

/**
 * Load the framework's legacy CodeIgniter common functions (get_config(),
 * config_item(), remove_invisible_characters(), is_cli(), etc.) so routing
 * classes that rely on them (LegacyRouter, URI, Config) can run in tests.
 * The file guards every function with function_exists(), and its own
 * get_config()/config_item() read from $GLOBALS['CFG'] which we seed below.
 */
$legacyCommon = __DIR__ . '/../../../framework/src/Support/Legacy/common.php';
if (is_file($legacyCommon)) {
    require_once $legacyCommon;
}

/**
 * Seed the global CI config object ($CFG) used by get_config()/config_item().
 */
if (!isset($GLOBALS['CFG'])) {
    $GLOBALS['CFG'] = new \stdClass();
}
if (!isset($GLOBALS['CFG']->config) || !is_array($GLOBALS['CFG']->config)) {
    $GLOBALS['CFG']->config = [
        'base_url' => 'http://localhost/',
        'index_page' => '',
        'uri_protocol' => 'REQUEST_URI',
        'permitted_uri_chars' => 'a-z 0-9~%.:_\\-',
        'enable_query_strings' => false,
    ];
}

if (!function_exists('log_message')) {
    /**
     * No-op logging shim for tests.
     *
     * @param string $level
     * @param string $message
     * @return void
     */
    function log_message($level, $message)
    {
        // Intentionally silent during unit tests.
    }
}

/**
 * Load the framework's global helper functions (app(), kodhe(),
 * get_instance()). In a real application these are registered by the
 * bootstrap; Router::__construct() and RouteCollection::__construct() call
 * app()->config->item(...) so they must exist in isolated tests too,
 * otherwise executing a resolved route fatals and looks like "not found".
 */
$consoleFunctions = __DIR__ . '/../../../framework/src/Console/Functions.php';
if (is_file($consoleFunctions)) {
    require_once $consoleFunctions;
}

/**
 * Wire a minimal Config instance into the facade so app()->config works.
 */
if (!\Kodhe\Framework\Support\Facades\Facade::getInstance()->has('config')) {
    \Kodhe\Framework\Support\Facades\Facade::getInstance()->set('config', new \Kodhe\Framework\Config\Config());
}

/**
 * RouteCollection::__construct() reads config_item('cache_path'), which is
 * an optional key that CI apps usually leave empty (falling back to
 * STORAGEPATH.'cache/'). config_item() returns NULL for missing keys, and
 * RouteCollection passes it straight into rtrim(), producing a fatal
 * "rtrim(): Argument #1 must be of type string, null given" when a resolved
 * route (e.g. a Closure route) is executed. Default the key here so the
 * routing pipeline runs in isolated tests.
 */
if (config_item('cache_path') === null) {
    $GLOBALS['CFG']->config['cache_path'] = '';
}
