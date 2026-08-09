<?php if (!defined('SITE')) exit('No direct script access allowed');

/**
 * Indexhibit unattended installer core.
 *
 * Extracted from ndxzstudio/install.php so the same logic can be invoked
 * by the web wizard, a CLI script, or a WHMCS-triggered HTTP endpoint.
 *
 * @version 2.1.6
 */
#[AllowDynamicProperties]
class IndexhibitInstaller
{
	public $charset = 'utf8';
	public $collate = 'utf8_unicode_ci';
	public $charset_collate;
	public $db;
	public $last_error = '';

	/** Default seed data used when optional parameters are omitted. */
	protected $seedDefaults = array(
		'theme'              => 'default',
		'home_section_title' => 'Main',
		'home_section_url'   => '/',
		'project_section_title' => 'Project',
		'project_section_url'   => '/project/',
		'tag_section_title'  => 'Tags',
		'tag_section_url'      => '/tag/',
		'header_html'        => '<h1><a href="/" title="{{obj_name}}">{{obj_name}}</a></h1>',
		'footer_html'        => "<p>Copyright 2007-2017<br /\n<a href=\"http://www.indexhibit.org/\">Built with Indexhibit</a></p>",
		'api_key'            => 'asdfsafasfadsfdfs',
		'user_hash'          => '5f8bfb51cc5c437a603abe3766d004d8',
		'admin_username'     => 'index1',
		'admin_password_hash'=> '22645ed8b5f5fa4b597d0fe61bed6a96'
	);

	/**
	 * Validate the server environment.
	 *
	 * @return array Associative array of checks. Each entry has keys:\t *               'ok' (bool), 'message' (string).
	 */
	public function checkEnvironment()
	{
		$checks = array();

		$checks['php_version'] = array(
			'ok' => version_compare(PHP_VERSION, '5.6.0', '>='),
			'message' => 'PHP ' . PHP_VERSION
		);

		$writable = array(
			'files' => DIRNAME . '/files',
			'files_gimgs' => DIRNAME . '/files/gimgs',
			'files_dimgs' => DIRNAME . '/files/dimgs',
			'config' => DIRNAME . '/ndxzsite/config'
		);

		foreach ($writable as $key => $path)
		{
			$checks[$key] = array(
				'ok' => is_dir($path) && is_writable($path),
				'message' => $path
			);
		}

		$checks['mysqli'] = array(
			'ok' => extension_loaded('mysqli'),
			'message' => 'mysqli extension'
		);

		$checks['image_library'] = array(
			'ok' => extension_loaded('gd') || extension_loaded('imagick'),
			'message' => extension_loaded('imagick') ? 'imagick' : 'gd'
		);

		return $checks;
	}

	/**
	 * Test whether the supplied database credentials work and the database exists.
	 *
	 * @param array $db Array with keys: db_host, db_user, db_password, db_name.
	 * @return bool
	 */
	public function testDatabaseConnection($db)
	{
		$link = @mysqli_connect($db['db_host'], $db['db_user'], $db['db_password']);

		if (!$link)
		{
			$this->last_error = 'Could not connect to database server: ' . mysqli_connect_error();
			return false;
		}

		$selected = @mysqli_select_db($link, $db['db_name']);
		mysqli_close($link);

		if (!$selected)
		{
			$this->last_error = 'Could not select database "' . $db['db_name'] . '".';
			return false;
		}

		return true;
	}

	/**
	 * Write the database configuration file.
	 *
	 * @param array $config Array with keys: db_name, db_user, db_password, db_host, table_prefix.
	 * @return bool
	 */
	public function writeConfig($config)
	{
		$path = DIRNAME . '/ndxzsite/config';
		$filename = $path . '/config.php';

		$somecontent = "<?php  if (!defined('SITE')) exit('No direct script access allowed');

\$indx['db'] 		= '" . $this->escapeConfigValue($config['db_name']) . "';
\$indx['user'] 		= '" . $this->escapeConfigValue($config['db_user']) . "';
\$indx['pass'] 		= '" . $this->escapeConfigValue($config['db_password']) . "';
\$indx['host'] 		= '" . $this->escapeConfigValue($config['db_host']) . "';
\$indx['sql']		= 'mysql';

if (!defined('PX')) { define('PX', '" . $this->escapeConfigValue($config['table_prefix']) . "'); }";

		if (!is_writable($path))
		{
			$this->last_error = 'Config directory is not writable: ' . $path;
			return false;
		}

		$handle = @fopen($filename, 'w');
		if (!$handle)
		{
			$this->last_error = 'Could not open config.php for writing.';
			return false;
		}

		if (fwrite($handle, $somecontent) === false)
		{
			$this->last_error = 'Could not write config.php.';
			fclose($handle);
			return false;
		}

		fclose($handle);
		return true;
	}

	/**
	 * Escape a value before writing it into the PHP config file.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function escapeConfigValue($value)
	{
		return str_replace(array("'", "\\"), array("\\'", "\\\\"), (string) $value);
	}

	/**
	 * Determine the correct table-engine / charset clause for this MySQL version.
	 *
	 * @return string
	 */
	public function setCharsetCollation()
	{
		$version = preg_replace('/[^0-9.].*/', '', mysqli_get_server_info($this->db->link));

		if (version_compare($version, '4.1', '>='))
		{
			$this->charset_collate = 'DEFAULT CHARACTER SET ' . $this->charset;
			$this->charset_collate .= ' COLLATE ' . $this->collate;
		}

		$ver = $this->mysqliVer($this->db->link);

		return ((is_numeric($ver) && $ver <= 4)) ? 'TYPE=MyISAM' : 'ENGINE=MyISAM DEFAULT CHARSET=utf8';
	}

	/**
	 * Get the major MySQL client version number.
	 *
	 * @return int
	 */
	public function mysqliVer()
	{
		$ver = mysqli_get_client_version();
		return (int) substr($ver, 0, 1);
	}

	/**
	 * Create tables and seed data for a fresh install.
	 *
	 * @param array $params Array with keys:
	 *                      site_name, admin_first_name, admin_last_name, admin_email,
	 *                      admin_username, admin_password_hash, user_language.
	 * @return bool
	 */
	public function installDatabase($params)
	{
		global $c, $picked, $indx;

		require_once '../ndxzsite/config/config.php';
		require_once './db/db.mysql.php';

		$GLOBALS['indx'] = $indx;
		$this->db = new Db();

		$isam = $this->setCharsetCollation();

		$sql = $this->schemaAndSeedSql($isam, $params);

		foreach ($sql as $install)
		{
			if (!$this->db->query($install))
			{
				$this->last_error = 'Database query failed during install.';
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the schema and seed SQL for a fresh install.
	 *
	 * @param string $isam Table-engine clause.
	 * @param array $params Install parameters.
	 * @return array Array of SQL strings.
	 */
	public function schemaAndSeedSql($isam, $params)
	{
		$site_name   = $this->db->escape($params['site_name']);
		$admin_email = $this->db->escape($params['admin_email']);
		$admin_first_name = $this->db->escape($params['admin_first_name']);
		$admin_last_name  = $this->db->escape($params['admin_last_name']);
		$admin_username   = $this->db->escape(isset($params['admin_username']) ? $params['admin_username'] : $this->seedDefaults['admin_username']);
		$admin_password_hash = $this->db->escape(isset($params['admin_password_hash']) ? $params['admin_password_hash'] : $this->seedDefaults['admin_password_hash']);
		$user_language = $this->db->escape(isset($params['user_language']) ? $params['user_language'] : 'en-us');

		$theme = $this->db->escape(isset($params['theme']) ? $params['theme'] : $this->seedDefaults['theme']);
		$home_title = $this->db->escape(isset($params['home_section_title']) ? $params['home_section_title'] : $this->seedDefaults['home_section_title']);
		$home_url   = $this->db->escape(isset($params['home_section_url']) ? $params['home_section_url'] : $this->seedDefaults['home_section_url']);
		$project_title = $this->db->escape(isset($params['project_section_title']) ? $params['project_section_title'] : $this->seedDefaults['project_section_title']);
		$project_url   = $this->db->escape(isset($params['project_section_url']) ? $params['project_section_url'] : $this->seedDefaults['project_section_url']);
		$tag_title = $this->db->escape(isset($params['tag_section_title']) ? $params['tag_section_title'] : $this->seedDefaults['tag_section_title']);
		$tag_url   = $this->db->escape(isset($params['tag_section_url']) ? $params['tag_section_url'] : $this->seedDefaults['tag_section_url']);
		$header_html = $this->db->escape(isset($params['header_html']) ? $params['header_html'] : $this->seedDefaults['header_html']);
		$footer_html = $this->db->escape(isset($params['footer_html']) ? $params['footer_html'] : $this->seedDefaults['footer_html']);
		$api_key = $this->db->escape(isset($params['api_key']) ? $params['api_key'] : $this->seedDefaults['api_key']);
		$user_hash = $this->db->escape(isset($params['user_hash']) ? $params['user_hash'] : $this->seedDefaults['user_hash']);

		$now = getNow();

		$sql = array();

		// ...existing schema/seed SQL extracted from install.php...

		$sql[] = "CREATE TABLE IF NOT EXISTS iptocountry (
		  ip_from double NOT NULL DEFAULT '0',
		  ip_to double NOT NULL DEFAULT '0',
		  country_code2 char(2) NOT NULL DEFAULT '',
		  country_code3 char(3) NOT NULL DEFAULT '',
		  country_name varchar(50) NOT NULL DEFAULT '',
		  KEY ip_from_to_idx (ip_from,ip_to)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."abstracts (
		  ab_id int(11) NOT NULL AUTO_INCREMENT,
		  ab_obj varchar(32) NOT NULL DEFAULT '',
		  ab_obj_id int(11) NOT NULL DEFAULT '0',
		  ab_var varchar(255) NOT NULL DEFAULT '',
		  abstract text,
		  PRIMARY KEY (ab_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."profile (
			pr_id int(11) NOT NULL AUTO_INCREMENT,
 			pr_apikey varchar(32) NOT NULL,
			pr_sitekey varchar(32) NOT NULL,
			pr_name varchar(250) NOT NULL,
			pr_title varchar(250) NOT NULL,
			pr_image varchar(1000) NOT NULL,
			pr_location varchar(250) NOT NULL,
			pr_freelance varchar(1) NOT NULL,
			PRIMARY KEY (pr_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."media (
		  media_id int(11) NOT NULL AUTO_INCREMENT,
		  media_ref_id smallint(6) NOT NULL DEFAULT '0',
		  media_obj_type varchar(15) NOT NULL DEFAULT '',
		  media_mime varchar(15) NOT NULL DEFAULT '',
		  media_tags varchar(255) NOT NULL DEFAULT '0',
		  media_file varchar(255) NOT NULL DEFAULT '',
		  media_thumb varchar(255) NOT NULL DEFAULT '',
		  media_file_replace varchar(255) NOT NULL DEFAULT '',
		  media_title varchar(255) NOT NULL DEFAULT '',
		  media_caption text NOT NULL,
		  media_x varchar(5) NOT NULL DEFAULT '',
		  media_y varchar(5) NOT NULL DEFAULT '',
		  media_xr smallint(4) NOT NULL DEFAULT '0',
		  media_yr smallint(4) NOT NULL DEFAULT '0',
		  media_kb mediumint(9) NOT NULL DEFAULT '0',
		  media_udate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  media_uploaded datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  media_order smallint(3) NOT NULL DEFAULT '999',
		  media_hide tinyint(1) NOT NULL DEFAULT '0',
		  media_dir varchar(255) NOT NULL DEFAULT '',
		  media_src varchar(25) NOT NULL DEFAULT '',
		  PRIMARY KEY (media_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."objects (
		  id int(11) NOT NULL AUTO_INCREMENT,
		  object varchar(100) NOT NULL DEFAULT '',
		  obj_ref_id int(4) NOT NULL DEFAULT '0',
		  title varchar(100) NOT NULL DEFAULT '',
		  content mediumtext NOT NULL,
		  home tinyint(1) NOT NULL DEFAULT '0',
		  link varchar(255) NOT NULL DEFAULT '',
		  target tinyint(1) NOT NULL DEFAULT '0',
		  iframe tinyint(1) NOT NULL DEFAULT '0',
		  new tinyint(1) NOT NULL DEFAULT '0',
		  tags varchar(250) NOT NULL DEFAULT '0',
		  header text NOT NULL,
		  udate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  pdate datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  creator tinyint(3) NOT NULL DEFAULT '0',
		  status tinyint(1) NOT NULL DEFAULT '0',
		  process tinyint(1) NOT NULL DEFAULT '1',
		  page_cache tinyint(1) NOT NULL DEFAULT '0',
		  section_id tinyint(3) NOT NULL DEFAULT '0',
		  section_top tinyint(1) NOT NULL DEFAULT '0',
		  section_sub varchar(255) NOT NULL DEFAULT '',
		  subdir tinyint(1) NOT NULL DEFAULT '0',
		  url varchar(250) NOT NULL DEFAULT '',
		  ord smallint(3) NOT NULL DEFAULT '999',
		  color varchar(7) NOT NULL DEFAULT 'ffffff',
		  bgimg varchar(255) NOT NULL DEFAULT '',
		  hidden tinyint(1) NOT NULL DEFAULT '0',
		  current tinyint(1) NOT NULL DEFAULT '0',
		  perm tinyint(1) NOT NULL DEFAULT '0',
		  media_source tinyint(3) NOT NULL DEFAULT '0',
		  media_source_detail varchar(255) NOT NULL,
		  images smallint(4) NOT NULL DEFAULT '9999',
		  thumbs_shape tinyint(1) NOT NULL DEFAULT '0',
		  thumbs smallint(4) NOT NULL DEFAULT '200',
		  format varchar(100) NOT NULL DEFAULT 'visual_index',
		  thumbs_format tinyint(1) NOT NULL DEFAULT '0',
		  operand tinyint(4) NOT NULL DEFAULT '0',
		  titling tinyint(1) NOT NULL DEFAULT '0',
		  break smallint(2) NOT NULL DEFAULT '0',
		  tiling tinyint(1) NOT NULL DEFAULT '1',
		  year varchar(4) NOT NULL DEFAULT '2010',
		  report tinyint(1) NOT NULL DEFAULT '0',
		  pwd varchar(100) NOT NULL DEFAULT '',
		  placement tinyint(1) NOT NULL DEFAULT '0',
		  template varchar(25) NOT NULL DEFAULT 'index.php',
		  ling varchar(7) NOT NULL DEFAULT 'en',
		  ling_id varchar(32) NOT NULL DEFAULT '',
		  serial longtext NOT NULL,
		  extra1 varchar(255) NOT NULL DEFAULT '',
		  extra2 varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."objects_prefs (
		  obj_id int(11) NOT NULL AUTO_INCREMENT,
		  obj_ref_type varchar(255) NOT NULL DEFAULT '',
		  obj_active tinyint(1) NOT NULL DEFAULT '1',
		  obj_title varchar(255) NOT NULL DEFAULT '',
		  obj_section smallint(3) NOT NULL DEFAULT '1',
		  obj_template varchar(50) NOT NULL DEFAULT '',
		  obj_members varchar(255) NOT NULL DEFAULT '',
		  obj_img varchar(255) NOT NULL DEFAULT '',
		  obj_settings longtext NOT NULL,
		  obj_group varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (obj_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."plugins (
		  pl_id int(4) NOT NULL AUTO_INCREMENT,
		  pl_primary tinyint(1) NOT NULL DEFAULT '0',
		  pl_type varchar(15) NOT NULL DEFAULT '',
		  pl_name varchar(255) NOT NULL DEFAULT '',
		  pl_uri varchar(255) NOT NULL DEFAULT '',
		  pl_version varchar(20) NOT NULL DEFAULT '',
		  pl_file varchar(255) NOT NULL DEFAULT '',
		  pl_function varchar(255) NOT NULL DEFAULT '',
		  pl_hook varchar(255) NOT NULL DEFAULT '',
		  pl_space varchar(100) NOT NULL DEFAULT '',
		  pl_creator varchar(50) NOT NULL DEFAULT '',
		  pl_www varchar(255) NOT NULL DEFAULT '',
		  pl_desc text NOT NULL,
		  pl_options text NOT NULL,
		  pl_options_build text NOT NULL,
		  pl_usage varchar(255) NOT NULL DEFAULT '',
		  pl_usage_desc varchar(255) NOT NULL DEFAULT '',
		  pl_order smallint(3) NOT NULL DEFAULT '100',
		  PRIMARY KEY (pl_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."sections (
		  secid tinyint(3) NOT NULL AUTO_INCREMENT,
		  section varchar(60) NOT NULL DEFAULT '',
		  sec_obj varchar(50) NOT NULL DEFAULT 'exhibits',
		  sec_ord tinyint(4) NOT NULL DEFAULT '0',
		  sec_disp tinyint(3) NOT NULL DEFAULT '1',
		  sec_hide tinyint(1) NOT NULL DEFAULT '0',
		  sec_pwd varchar(32) NOT NULL DEFAULT '',
		  sec_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  sec_path varchar(250) NOT NULL DEFAULT '',
		  sec_subs varchar(100) NOT NULL DEFAULT '',
		  sec_desc varchar(100) NOT NULL DEFAULT '',
		  sec_proj tinyint(4) NOT NULL DEFAULT '0',
		  sec_group tinyint(4) NOT NULL DEFAULT 0,
		  sec_report tinyint(1) NOT NULL DEFAULT '0',
		  PRIMARY KEY (secid)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."settings (
		  adm_id tinyint(3) NOT NULL AUTO_INCREMENT,
		  site_name varchar(40) NOT NULL DEFAULT '',
		  installdate varchar(20) NOT NULL DEFAULT '',
		  version varchar(25) NOT NULL DEFAULT '',
		  curr_time tinyint(3) NOT NULL DEFAULT '0',
		  site_lang varchar(8) NOT NULL DEFAULT 'en-us',
		  time_format varchar(25) NOT NULL DEFAULT '',
		  tagging tinyint(1) NOT NULL DEFAULT '1',
		  help tinyint(1) NOT NULL DEFAULT '0',
		  caching tinyint(1) NOT NULL DEFAULT '0',
		  hibernate varchar(255) NOT NULL DEFAULT '',
		  obj_name varchar(255) NOT NULL DEFAULT '',
		  obj_theme varchar(50) NOT NULL DEFAULT '',
		  obj_itop text NOT NULL,
		  obj_ibot text NOT NULL,
		  obj_org tinyint(1) NOT NULL DEFAULT '1',
		  obj_apikey varchar(32) NOT NULL DEFAULT '',
		  site_format varchar(30) NOT NULL DEFAULT '%d %B %Y',
		  site_offset tinyint(3) NOT NULL DEFAULT '0',
		  site_vars longtext NOT NULL,
		  PRIMARY KEY (adm_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."stats (
		  hit_id int(14) NOT NULL AUTO_INCREMENT,
		  hit_addr varchar(16) NOT NULL DEFAULT '',
		  hit_country varchar(30) NOT NULL DEFAULT '',
		  hit_lang varchar(10) NOT NULL DEFAULT '',
		  hit_domain varchar(100) NOT NULL DEFAULT '',
		  hit_referrer varchar(100) NOT NULL DEFAULT '',
		  hit_page varchar(100) NOT NULL DEFAULT '',
		  hit_agent varchar(250) NOT NULL DEFAULT '',
		  hit_keyword varchar(250) NOT NULL DEFAULT '',
		  hit_os varchar(20) NOT NULL DEFAULT '',
		  hit_browser varchar(20) NOT NULL DEFAULT '',
		  hit_time datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  hit_month varchar(7) NOT NULL DEFAULT '',
		  hit_day date NOT NULL DEFAULT '0000-00-00',
		  PRIMARY KEY (hit_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."stats_exhibits (
		  stor_url varchar(255) NOT NULL DEFAULT '',
		  stor_count smallint(6) NOT NULL DEFAULT '0'
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."stats_storage (
		  stor_date varchar(7) NOT NULL DEFAULT '0000-00',
		  stor_hits int(11) NOT NULL DEFAULT '0',
		  stor_unique int(11) NOT NULL DEFAULT '0',
		  stor_referrer int(11) NOT NULL DEFAULT '0'
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."subsections (
		  sub_id tinyint(3) NOT NULL AUTO_INCREMENT,
		  sub_sec_id tinyint(3) NOT NULL DEFAULT 0,
		  sub_title varchar(255) NOT NULL DEFAULT '',
		  sub_folder varchar(255) NOT NULL DEFAULT '',
		  sub_order tinyint(3) NOT NULL DEFAULT 0,
		  sub_hide tinyint(1) NOT NULL DEFAULT 0,
		  PRIMARY KEY (sub_id)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."tagged (
		  tagged_prim int(11) NOT NULL AUTO_INCREMENT,
		  tagged_id smallint(6) NOT NULL DEFAULT 0,
		  tagged_object varchar(3) NOT NULL DEFAULT '',
		  tagged_obj_id smallint(6) NOT NULL DEFAULT 0,
		  PRIMARY KEY (tagged_prim)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."tags (
		  tag_id int(11) NOT NULL AUTO_INCREMENT,
		  tag_name varchar(255) NOT NULL DEFAULT '',
		  tag_group smallint(3) NOT NULL DEFAULT '1',
		  tag_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  tag_icon varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (tag_id),
		  UNIQUE KEY tag_name (tag_name)
		) $isam ;";

		$sql[] = "CREATE TABLE IF NOT EXISTS ".PX."users (
		  ID int(11) NOT NULL AUTO_INCREMENT,
		  userid varchar(100) NOT NULL DEFAULT '',
		  password varchar(32) NOT NULL DEFAULT '',
		  email varchar(100) NOT NULL DEFAULT '',
		  threads tinyint(3) NOT NULL DEFAULT '10',
		  writing tinyint(1) NOT NULL DEFAULT '0',
		  user_offset tinyint(3) NOT NULL DEFAULT '0',
		  user_format varchar(30) NOT NULL DEFAULT '%d %B %Y',
		  user_lang varchar(8) NOT NULL DEFAULT 'en-us',
		  user_hash varchar(32) NOT NULL DEFAULT '',
		  user_help tinyint(1) NOT NULL DEFAULT '0',
		  user_mode tinyint(1) NOT NULL DEFAULT '0',
		  user_name varchar(35) NOT NULL DEFAULT '',
		  user_surname varchar(35) NOT NULL DEFAULT '',
		  user_admin tinyint(1) NOT NULL DEFAULT '0',
		  user_active tinyint(1) NOT NULL DEFAULT '1',
		  user_client tinyint(1) NOT NULL DEFAULT '0',
		  user_img varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (ID),
		  UNIQUE KEY userid (userid),
		  KEY ID (ID)
		) $isam ;";

		$sql[] = "INSERT INTO `".PX."objects` (`id`, `object`, `obj_ref_id`, `title`, `content`, `home`, `link`, `target`, `iframe`, `new`, `tags`, `header`, `udate`, `pdate`, `creator`, `status`, `process`, `page_cache`, `section_id`, `section_top`, `url`, `ord`, `color`, `bgimg`, `hidden`, `current`, `perm`, `media_source`, `media_source_detail`, `images`, `thumbs_shape`, `thumbs`, `format`, `thumbs_format`, `operand`, `titling`, `break`, `tiling`, `year`, `report`, `pwd`, `placement`, `template`, `ling`, `ling_id`, `serial`, `extra1`, `extra2`) VALUES
		(1, 'exhibits', 1, $home_title, '', 1, '', 0, 0, 0, '2', '', '$now', '$now', 1, 1, 1, 0, 1, 1, $home_url, 0, 'ffffff', '', 0, 0, 0, 0, '', 600, 0, 200, 'visual_index', 0, 2, 1, 0, 1, '2011', 0, '', 0, 'index.php', 'en', '', '', '', ''),
		(2, 'exhibits', 2, $project_title, '', 0, '', 0, 0, 0, '0', '', '$now', '$now', 1, 1, 1, 0, 2, 1, $project_url, 0, 'ffffff', '', 0, 0, 0, 2, '', 9999, 0, 200, 'visual_index', 0, 0, 1, 0, 1, '2011', 0, '', 1, 'index.php', 'en', '', '', '', ''),
		(3, 'exhibits', 3, $tag_title, '', 0, '', 0, 0, 0, '0', '', '$now', '$now', 1, 0, 1, 0, 3, 1, $tag_url, 0, 'ffffff', '', 0, 0, 0, 0, '', 9999, 0, 200, 'visual_index', 0, 0, 0, 0, 1, '2011', 0, '', 0, 'index.php', 'en', '', '', '', '');";

		$sql[] = "INSERT INTO `".PX."objects_prefs` (`obj_id`, `obj_ref_type`, `obj_active`, `obj_title`, `obj_section`, `obj_template`, `obj_members`, `obj_img`, `obj_settings`, `obj_group`) VALUES
		(1, 'exhibits', 1, '', 1, '', '', '', '', ''),
		(2, 'xml', 1, '', 1, '', '', '', '', ''),
		(3, 'tag', 1, '', 1, '', '', '', 'a:7:{s:10:\"section_id\";s:1:\"3\";s:8:\"template\";s:7:\"tag\\.php\";s:6:\"format\";s:12:\"visual_index\";s:6:\"thumbs\";s:3:\"200\";s:12:\"thumbs_shape\";s:1:\"0\";s:5:\"break\";s:1:\"0\";s:7:\"titling\";s:1:\"0\";}', '');";

		$sql[] = "INSERT INTO `".PX."sections` (`secid`, `section`, `sec_obj`, `sec_ord`, `sec_disp`, `sec_hide`, `sec_pwd`, `sec_date`, `sec_path`, `sec_subs`, `sec_desc`, `sec_proj`, `sec_group`, `sec_report`) VALUES
		(1, 'root', 'exhibits', 2, 1, 0, '', '2006-12-20 17:01:31', '/', '', $home_title, 0, 0, 0),
		(2, 'project', 'exhibits', 1, 1, 0, '', '2010-03-03 23:48:44', '/project', '', $project_title, 0, 0, 0),
		(3, 'tag', 'exhibits', 3, 1, 1, '', '2010-03-04 05:51:22', '/tag', '', $tag_title, 3, 0, 0);";

		$sql[] = "INSERT INTO `".PX."settings` (`adm_id`, `site_name`, `installdate`, `version`, `curr_time`, `site_lang`, `time_format`, `tagging`, `help`, `hibernate`, `obj_name`, `obj_theme`, `obj_itop`, `obj_ibot`, `obj_org`, `obj_apikey`, `site_format`, `site_offset`, `site_vars`) VALUES
		(1, $site_name, '$now', '" . VERSION . "', 1, 'en-us', '%d %B %Y', 1, 0, '', $site_name, $theme, $header_html, $footer_html, 2, $api_key, '%d %B %Y', 0, 'a:3:{s:9:\"passwords\";s:1:\"1\";s:9:\"templates\";s:1:\"0\";s:4:\"tags\";s:1:\"1\";}');";

		$sql[] = "INSERT INTO `".PX."users` (`ID`, `userid`, `password`, `email`, `threads`, `writing`, `user_offset`, `user_format`, `user_lang`, `user_hash`, `user_help`, `user_mode`, `user_name`, `user_surname`, `user_admin`, `user_active`, `user_client`) VALUES
		(1, $admin_username, $admin_password_hash, $admin_email, 15, 0, 0, '%d %B %Y', $user_language, $user_hash, 0, 1, $admin_first_name, $admin_last_name, 1, 1, 0);";

		return $sql;
	}

	/**
	 * Convenience wrapper: write config + install database in one call.
	 *
	 * @param array $db Array with keys: db_host, db_user, db_password, db_name, table_prefix.
	 * @param array $admin Array with keys: site_name, admin_first_name, admin_last_name,
	 *                     admin_email, admin_username, admin_password_hash, user_language.
	 * @return bool
	 */
	public function run($db, $admin)
	{
		if (!$this->writeConfig($db))
		{
			return false;
		}

		return $this->installDatabase($admin);
	}
}
