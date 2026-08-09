<?php define('SITE', 'Bonjour!');

/**
 * Dry-run test harness for the unattended installer.
 *
 * This script does NOT modify files or databases. It validates the input,
 * environment checks, and database connection, then reports what it would do.
 *
 * Expected input: same JSON/POST fields as ndxzstudio/auto-install.php.
 */

// annoying date setting thing
if (function_exists("date_default_timezone_set") && function_exists("date_default_timezone_get"))
{
	@date_default_timezone_set(@date_default_timezone_get());
}

require_once '../ndxzsite/config/options.php';
require_once 'defaults.php';
require_once 'common.php';
require_once './helper/entrance.php';
require_once './helper/html.php';
require_once './helper/time.php';
require_once './lang/index.php';
require_once './lib/installer.php';

function send_json($data, $http_code = 200)
{
	http_response_code($http_code);
	header('Content-Type: application/json; charset=utf-8');
	exit(json_encode($data, JSON_PRETTY_PRINT));
}

function get_input()
{
	$input = array();
	$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
	if (stripos($content_type, 'application/json') !== false)
	{
		$raw = file_get_contents('php://input');
		$json = json_decode($raw, true);
		if (is_array($json)) $input = $json;
	}
	if (empty($input)) $input = array_merge($_POST, $_GET);
	return $input;
}

function clean($value, $max = 255)
{
	$value = trim((string) $value);
	return substr($value, 0, $max);
}

$input = get_input();

$db = array(
	'db_host'      => clean($input['db_host'] ?? '', 100),
	'db_name'      => clean($input['db_name'] ?? '', 65),
	'db_user'      => clean($input['db_user'] ?? '', 32),
	'db_password'  => clean($input['db_password'] ?? '', 255),
	'table_prefix' => clean($input['table_prefix'] ?? 'ndxzbt_', 20)
);

$installer = new IndexhibitInstaller;

$env = $installer->checkEnvironment();
$env_failures = array();
foreach ($env as $key => $check)
{
	if (!$check['ok']) $env_failures[$key] = $check['message'];
}

$can_connect = false;
if (empty($env_failures) && !empty($db['db_host']))
{
	$can_connect = $installer->testDatabaseConnection($db);
}

send_json(array(
	'success' => true,
	'message' => 'Dry-run complete.',
	'details' => array(
		'environment' => $env,
		'environment_failures' => $env_failures,
		'db_connect' => $can_connect,
		'db_error' => $installer->last_error,
		'would_write_config' => !file_exists(DIRNAME . '/ndxzsite/config/config.php'),
		'config_path' => DIRNAME . '/ndxzsite/config/config.php'
	)
), 200);
