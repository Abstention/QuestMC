<?php
/*
 * there is NO SUPPORT offered for this script.
 * this script is provided AS IS and without any warranty.
 * this script was made with the primary goal of making the install process automatic for hosting providers + our API test suite.
 */

// TODO: Print nice message if a required environment variable is not set

if (php_sapi_name() != 'cli') {
    die('This script must be run from the command line.');
}

if (!isset($argv[1]) || isset($argv[1]) && $argv[1] !== '--iSwearIKnowWhatImDoing') {
    print("You don't know what you're doing." . PHP_EOL);
    exit(1);
}

print(PHP_EOL);

$reinstall = false;
if (isset($argv[2]) && $argv[2] == '--reinstall') {
    $reinstall = true;
    print('🧨 Reinstall mode enabled! ' . PHP_EOL . PHP_EOL);
}

if (!file_exists('./vendor/autoload.php')) {
    print('⚠️  You need to run "composer install" first!' . PHP_EOL);
    exit(1);
}

if (!$reinstall && file_exists('./core/config.php')) {
    print('⚠️  NamelessMC is already installed! ' . PHP_EOL);
    print('🧨 If you want to reinstall, run this script with the --reinstall flag.' . PHP_EOL);
    exit(1);
}

$start = microtime(true);

print('🗑  Deleting cache directories...' . PHP_EOL);
// clear the cache directories
$folders = [
    './cache',
    './cache/templates_c'
];
foreach ($folders as $folder) {
    if (is_dir($folder)) {
        $files = glob($folder . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

if ($reinstall) {
    print('🗑  Deleting old config.php file...' . PHP_EOL);
    // delete the core/config.php file
    if (is_file('./core/config.php')) {
        unlink('./core/config.php');
    }
}

define('ROOT_PATH', dirname(__FILE__));

print('✍️  Creating new config.php file...' . PHP_EOL);
$conf = [
    'mysql' => [
        'host' => getenv('NAMELESS_DATABASE_ADDRESS') ?: '127.0.0.1',
        'port' => getenv('NAMELESS_DATABASE_PORT') ?: '3306',
        'username' => getenv('NAMELESS_DATABASE_USERNAME') ?: 'root',
        'password' => getenv('NAMELESS_DATABASE_PASSWORD') ?: '',
        'db' => getenv('NAMELESS_DATABASE_NAME') ?: 'nameless',
        'prefix' => 'nl2_',
        'charset' => getenv('NAMELESS_DATABASE_CHARSET') ?: 'utf8mb4',
        'engine' => getenv('NAMELESS_DATABASE_ENGINE') ?: 'InnoDB',
        'initialise_charset' => true,
    ],
    'remember' => [
        'cookie_name' => 'nl2',
        'cookie_expiry' => 604800,
    ],
    'session' => [
        'session_name' => '2user',
        'admin_name' => '2admin',
        'token_name' => '2token',
    ],
    'core' => [
        'hostname' => getenv('NAMELESS_HOSTNAME') ?: 'localhost',
        'path' => getenv('NAMELESS_PATH') ?: '',
        'friendly' => getenv('NAMELESS_FRIENDLY_URLS') ?: false,
        'force_https' => false,
        'force_www' => false,
        'captcha' => false,
    ],
    'allowedProxies' => '',
];

file_put_contents(
    './core/config.php',
    '<?php' . PHP_EOL . '$conf = ' . var_export($conf, true) . ';'
);
$GLOBALS['config'] = $conf;

print('♻️  Registering autoloader...' . PHP_EOL);
require './vendor/autoload.php';
require './core/autoload.php';

if ($reinstall) {
    print('🗑️  Deleting old database...' . PHP_EOL);
    DB_Custom::getInstance($conf['mysql']['host'], $conf['mysql']['db'], $conf['mysql']['username'], $conf['mysql']['password'], $conf['mysql']['port'])->createQuery('DROP DATABASE IF EXISTS `' . $conf['mysql']['db'] . '`');
    print('✍️  Creating new database...' . PHP_EOL);
    DB_Custom::getInstance($conf['mysql']['host'], $conf['mysql']['db'], $conf['mysql']['username'], $conf['mysql']['password'], $conf['mysql']['port'])->createQuery('CREATE DATABASE `' . $conf['mysql']['db'] . '`');
}

print('✍️  Creating tables...' . PHP_EOL);
$queries = new Queries();
$queries->dbInitialise();

Session::put('default_language', getenv('NAMELESS_DEFAULT_LANGUAGE') ?: 'EnglishUK');

$nameless_terms = 'This website uses "Nameless" website software. The ' .
    '"Nameless" software creators will not be held responsible for any content ' .
    'that may be experienced whilst browsing this site, nor are they responsible ' .
    'for any loss of data which may come about, for example a hacking attempt. ' .
    'The website is run independently from the software creators, and any content' .
    ' is the responsibility of the website administration.';

print('✍️  Inserting default data to database...' . PHP_EOL);
require './core/installation/views/includes/site_initialize.php';
$queries->create('settings', [
    'name' => 'sitename',
    'value' => Output::getClean(getenv('NAMELESS_SITE_NAME') ?: ''),
]);
$queries->create('settings', [
    'name' => 'incoming_email',
    'value' => Output::getClean(getenv('NAMELESS_SITE_CONTACT_EMAIL') ?: ''),
]);
$queries->create('settings', [
    'name' => 'outgoing_email',
    'value' => Output::getClean(getenv('NAMELESS_SITE_OUTGOING_EMAIL') ?: ''),
]);

print('✍️  Creating admin account...' . PHP_EOL);
$user = new User();
$user->create([
    'username' => Output::getClean(getenv('NAMELESS_ADMIN_USERNAME') ?: 'admin'),
    'nickname' => Output::getClean(getenv('NAMELESS_ADMIN_USERNAME') ?: 'admin'),
    'password' => password_hash(getenv('NAMELESS_ADMIN_PASSWORD') ?: 'password', PASSWORD_BCRYPT, ['cost' => 13]),
    'pass_method' => 'default',
    'uuid' => '', // TODO Get UUID from mojang
    'joined' => date('U'),
    'email' => Output::getClean(getenv('NAMELESS_ADMIN_EMAIL')),
    'lastip' => '127.0.0.1',
    'active' => 1,
    'last_online' => date('U'),
    'theme_id' => 1,
    'language_id' => $queries->getWhere('languages', ['is_default', '=', 1])[0]->id,
]);
DB::getInstance()->createQuery('INSERT INTO `nl2_users_groups` (`user_id`, `group_id`, `received`, `expire`) VALUES (?, ?, ?, ?)', [
    1,
    2,
    date('U'),
    0,
]);

print(PHP_EOL . '✅ Installation complete! (Took ' . round(microtime(true) - $start, 2) . ' seconds)' . PHP_EOL);
print(PHP_EOL . '🖥  URL: http://' . $conf['core']['hostname'] . $conf['core']['path']);
print(PHP_EOL . '🔑 Admin username: ' . Output::getClean(getenv('NAMELESS_ADMIN_USERNAME') ?: 'admin'));
print(PHP_EOL . '🔑 Admin email: ' . Output::getClean(getenv('NAMELESS_ADMIN_EMAIL')));
print(PHP_EOL . '🔑 Admin password: ' . Output::getClean(getenv('NAMELESS_ADMIN_PASSWORD') ?: 'password'));
print(PHP_EOL);
exit(0);
