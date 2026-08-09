<?php define('SITE', 'Bonjour!');

/**
 * Headless auto-install endpoint for Indexhibit.
 *
 * Intended to be called by WHMCS (or any other provisioning system) to
 * install Indexhibit without requiring the browser-based wizard.
 *
 * Expected input (application/x-www-form-urlencoded or application/json):
 *   site_name          string  required
 *   admin_first_name   string  required
 *   admin_last_name    string  required
 *   admin_email        string  required
 *   admin_username     string  optional (default: index1)
 *   admin_password     string  optional (default: exhibit)
 *   db_host            string  required
 *   db_name            string  required
 *   db_user            string  required
 *   db_password        string  required
 *   table_prefix       string  optional (default: ndxzbt_)
 *   user_language      string  optional (default: en-us)
 *   theme              string  optional (default: default)
 *   home_section_title string  optional (default: Main)
 *   project_section_title string optional (default: Project)
 *   tag_section_title  string  optional (default: Tags)
 *   header_html        string  optional
 *   footer_html        string  optional
 *   api_key            string  optional (auto-generated if omitted)
 *   auth_token         string  optional if not configured
 *
 * Response: JSON object with keys:
 *   success   bool
 *   message   string
 *   details   array (only on failure)
 *
 * @version 2.1.6
 */

// annoying date setting thing
if (function_exists("date_default_timezone_set") && function_exists("date_default_timezone_get"))
{
	@date_default_timezone_set(@date_default_timezone_get());
}

// Load the same bootstrap files the web installer uses.
require_once '../ndxzsite/config/options.php';
require_once 'defaults.php';
require_once 'common.php';
require_once './helper/entrance.php';
require_once './helper/html.php';
require_once './helper/time.php';
require_once './lang/index.php';
require_once './lib/installer.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// If set, the caller must provide this exact token in the `auth_token` field.
// Use a strong random string for production deployments.
$required_auth_token = '';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function send_json($data, $http_code = 200)
{
	http_response_code($http_code);
	header('Content-Type: application/json; charset=utf-8');
	exit(json_encode($data, JSON_PRETTY_PRINT));
}

function get_input()
{
	$input = array();

	// Prefer JSON bodies.
	$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
	if (stripos($content_type, 'application/json') !== false)
	{
		$raw = file_get_contents('php://input');
		$json = json_decode($raw, true);
		if (is_array($json))
		{
			$input = $json;
		}
	}

	// Fall back to POST/GET parameters.
	if (empty($input))
	{
		$input = array_merge($_POST, $_GET);
	}

	return $input;
}

function clean($value, $max = 255)
{
	$value = trim((string) $value);
	$value = substr($value, 0, $max);
	return $value;
}

function required_fields()
{
	return array(
		'site_name',
		'admin_first_name',
		'admin_last_name',
		'admin_email',
		'db_host',
		'db_name',
		'db_user',
		'db_password'
	);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$input = get_input();

// Auth token check (only if one is configured).
if ($required_auth_token !== '') {
	if (empty($input['auth_token']) || $input['auth_token'] !== $required_auth_token) {
		send_json(array(
			'success' => false,
			'message' => 'Unauthorized: invalid or missing auth_token.'
		), 403);
	}
}

// Lock check: refuse to run if already installed.
if (file_exists(DIRNAME . '/ndxzsite/config/config.php'))
{
	send_json(array(
		'success' => false,
		'message' => 'Already installed: ndxzsite/config/config.php exists.'
	), 409);
}

// Validate required fields.
$missing = array();
foreach (required_fields() as $field)
{
	if (empty($input[$field]))
	{
		$missing[] = $field;
	}
}

if (!empty($missing))
{
	send_json(array(
		'success' => false,
		'message' => 'Missing required fields.',
		'details' => $missing
	), 400);
}

// Build the database and admin parameter arrays.
$db = array(
	'db_host'      => clean($input['db_host'], 100),
	'db_name'      => clean($input['db_name'], 65),
	'db_user'      => clean($input['db_user'], 32),
	'db_password'  => clean($input['db_password'], 255),
	'table_prefix' => clean($input['table_prefix'], 20)
);

// Sensible default for table prefix.
if ($db['table_prefix'] === '')
{
	$db['table_prefix'] = 'ndxzbt_';
}

$admin_username = clean($input['admin_username'], 100);
if ($admin_username === '')
{
	$admin_username = 'index1';
}

$admin_password = clean($input['admin_password'], 255);
if ($admin_password === '')
{
	$admin_password = 'exhibit';
}

$admin = array(
	'site_name'           => clean($input['site_name'], 35),
	'admin_first_name'    => clean($input['admin_first_name'], 35),
	'admin_last_name'     => clean($input['admin_last_name'], 35),
	'admin_email'         => clean($input['admin_email'], 100),
	'admin_username'      => $admin_username,
	'admin_password_hash' => md5($admin_password),
	'user_language'       => !empty($input['user_language']) ? clean($input['user_language'], 8) : 'en-us',
	'theme'               => !empty($input['theme']) ? clean($input['theme'], 50) : 'default',
	'home_section_title'  => !empty($input['home_section_title']) ? clean($input['home_section_title'], 100) : 'Main',
	'project_section_title' => !empty($input['project_section_title']) ? clean($input['project_section_title'], 100) : 'Project',
	'tag_section_title'   => !empty($input['tag_section_title']) ? clean($input['tag_section_title'], 100) : 'Tags',
	'header_html'         => isset($input['header_html']) ? $input['header_html'] : '',
	'footer_html'         => isset($input['footer_html']) ? $input['footer_html'] : '',
	'api_key'             => !empty($input['api_key']) ? clean($input['api_key'], 32) : substr(md5(uniqid(rand(), true)), 0, 32)
);

$installer = new IndexhibitInstaller;

// Environment validation.
$env = $installer->checkEnvironment();
$env_failures = array();
foreach ($env as $key => $check)
{
	if (!$check['ok'])
	{
		$env_failures[$key] = $check['message'];
	}
}

if (!empty($env_failures))
{
	send_json(array(
		'success' => false,
		'message' => 'Server environment does not meet install requirements.',
		'details' => $env_failures
	), 400);
}

// Database connectivity.
if (!$installer->testDatabaseConnection($db))
{
	send_json(array(
		'success' => false,
		'message' => $installer->last_error
	), 400);
}

// Run the install.
if (!$installer->run($db, $admin))
{
	send_json(array(
		'success' => false,
		'message' => $installer->last_error
	), 500);
}

// Install succeeded.
send_json(array(
	'success' => true,
	'message' => 'Indexhibit installed successfully.',
	'details' => array(
		'admin_url'      => BASEURL . BASENAME . '/',
		'admin_username' => $admin_username,
		'cleanup'        => 'Consider removing ndxzstudio/install.php and ndxzstudio/auto-install.php now.'
	)
), 200);
