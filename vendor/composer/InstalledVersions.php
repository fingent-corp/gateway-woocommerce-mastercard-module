<?php











namespace Composer;

use Composer\Autoload\ClassLoader;
use Composer\Semver\VersionParser;








class InstalledVersions
{
private static $installed = array (
  'root' => 
  array (
    'pretty_version' => '1.4.1',
    'version' => '1.4.1.0',
    'aliases' => 
    array (
    ),
    'reference' => NULL,
    'name' => '__root__',
  ),
  'versions' => 
  array (
    '__root__' => 
    array (
      'pretty_version' => '1.4.1',
      'version' => '1.4.1.0',
      'aliases' => 
      array (
      ),
      'reference' => NULL,
    ),
    'clue/stream-filter' => 
    array (
      'pretty_version' => 'v1.6.0',
      'version' => '1.6.0.0',
      'aliases' => 
      array (
      ),
      'reference' => 'd6169430c7731d8509da7aecd0af756a5747b78e',
    ),
    'guzzlehttp/psr7' => 
    array (
      'pretty_version' => '1.9.1',
      'version' => '1.9.1.0',
      'aliases' => 
      array (
      ),
      'reference' => 'e4490cabc77465aaee90b20cfc9a770f8c04be6b',
    ),
    'monolog/monolog' => 
    array (
      'pretty_version' => '1.27.1',
      'version' => '1.27.1.0',
      'aliases' => 
      array (
      ),
      'reference' => '904713c5929655dc9b97288b69cfeedad610c9a1',
    ),
    'php-http/async-client-implementation' => 
    array (
      'provided' => 
      array (
        0 => '*',
        1 => '1.0',
      ),
    ),
    'php-http/client-common' => 
    array (
      'pretty_version' => '1.11.0',
      'version' => '1.11.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '11d34cad40647848aa98536494f9da63571af9da',
    ),
    'php-http/client-implementation' => 
    array (
      'provided' => 
      array (
        0 => '*',
        1 => '1.0',
      ),
    ),
    'php-http/curl-client' => 
    array (
      'pretty_version' => 'v1.7.1',
      'version' => '1.7.1.0',
      'aliases' => 
      array (
      ),
      'reference' => '6341a93d00e5d953fc868a3928b5167e6513f2b6',
    ),
    'php-http/discovery' => 
    array (
      'pretty_version' => '1.19.2',
      'version' => '1.19.2.0',
      'aliases' => 
      array (
      ),
      'reference' => '61e1a1eb69c92741f5896d9e05fb8e9d7e8bb0cb',
    ),
    'php-http/httplug' => 
    array (
      'pretty_version' => 'v1.1.0',
      'version' => '1.1.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '1c6381726c18579c4ca2ef1ec1498fdae8bdf018',
    ),
    'php-http/logger-plugin' => 
    array (
      'pretty_version' => '1.3.0',
      'version' => '1.3.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '985bd29f52f6381ee0407edc86fc36472df33629',
    ),
    'php-http/message' => 
    array (
      'pretty_version' => '1.16.0',
      'version' => '1.16.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '47a14338bf4ebd67d317bf1144253d7db4ab55fd',
    ),
    'php-http/message-factory' => 
    array (
      'pretty_version' => '1.1.0',
      'version' => '1.1.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '4d8778e1c7d405cbb471574821c1ff5b68cc8f57',
    ),
    'php-http/message-factory-implementation' => 
    array (
      'provided' => 
      array (
        0 => '1.0',
      ),
    ),
    'php-http/promise' => 
    array (
      'pretty_version' => '1.2.1',
      'version' => '1.2.1.0',
      'aliases' => 
      array (
      ),
      'reference' => '44a67cb59f708f826f3bec35f22030b3edb90119',
    ),
    'psr/http-client-implementation' => 
    array (
      'provided' => 
      array (
        0 => '*',
      ),
    ),
    'psr/http-factory-implementation' => 
    array (
      'provided' => 
      array (
        0 => '*',
      ),
    ),
    'psr/http-message' => 
    array (
      'pretty_version' => '1.1',
      'version' => '1.1.0.0',
      'aliases' => 
      array (
      ),
      'reference' => 'cb6ce4845ce34a8ad9e68117c10ee90a29919eba',
    ),
    'psr/http-message-implementation' => 
    array (
      'provided' => 
      array (
        0 => '*',
        1 => '1.0',
      ),
    ),
    'psr/log' => 
    array (
      'pretty_version' => '1.1.4',
      'version' => '1.1.4.0',
      'aliases' => 
      array (
      ),
      'reference' => 'd49695b909c3b7628b6289db5479a1c204601f11',
    ),
    'psr/log-implementation' => 
    array (
      'provided' => 
      array (
        0 => '1.0.0',
      ),
    ),
    'ralouphie/getallheaders' => 
    array (
      'pretty_version' => '3.0.3',
      'version' => '3.0.3.0',
      'aliases' => 
      array (
      ),
      'reference' => '120b605dfeb996808c31b6477290a714d356e822',
    ),
    'symfony/deprecation-contracts' => 
    array (
      'pretty_version' => 'v2.5.2',
      'version' => '2.5.2.0',
      'aliases' => 
      array (
      ),
      'reference' => 'e8b495ea28c1d97b5e0c121748d6f9b53d075c66',
    ),
    'symfony/options-resolver' => 
    array (
      'pretty_version' => 'v5.4.21',
      'version' => '5.4.21.0',
      'aliases' => 
      array (
      ),
      'reference' => '4fe5cf6ede71096839f0e4b4444d65dd3a7c1eb9',
    ),
    'symfony/polyfill-php73' => 
    array (
      'pretty_version' => 'v1.28.0',
      'version' => '1.28.0.0',
      'aliases' => 
      array (
      ),
      'reference' => 'fe2f306d1d9d346a7fee353d0d5012e401e984b5',
    ),
    'symfony/polyfill-php80' => 
    array (
      'pretty_version' => 'v1.28.0',
      'version' => '1.28.0.0',
      'aliases' => 
      array (
      ),
      'reference' => '6caa57379c4aec19c0a12a38b59b26487dcfe4b5',
    ),
  ),
);
private static $canGetVendors;
private static $installedByVendor = array();







public static function getInstalledPackages()
{
$packages = array();
foreach (self::getInstalled() as $installed) {
$packages[] = array_keys($installed['versions']);
}

if (1 === \count($packages)) {
return $packages[0];
}

return array_keys(array_flip(\call_user_func_array('array_merge', $packages)));
}









public static function isInstalled($packageName)
{
foreach (self::getInstalled() as $installed) {
if (isset($installed['versions'][$packageName])) {
return true;
}
}

return false;
}














public static function satisfies(VersionParser $parser, $packageName, $constraint)
{
$constraint = $parser->parseConstraints($constraint);
$provided = $parser->parseConstraints(self::getVersionRanges($packageName));

return $provided->matches($constraint);
}










public static function getVersionRanges($packageName)
{
foreach (self::getInstalled() as $installed) {
if (!isset($installed['versions'][$packageName])) {
continue;
}

$ranges = array();
if (isset($installed['versions'][$packageName]['pretty_version'])) {
$ranges[] = $installed['versions'][$packageName]['pretty_version'];
}
if (array_key_exists('aliases', $installed['versions'][$packageName])) {
$ranges = array_merge($ranges, $installed['versions'][$packageName]['aliases']);
}
if (array_key_exists('replaced', $installed['versions'][$packageName])) {
$ranges = array_merge($ranges, $installed['versions'][$packageName]['replaced']);
}
if (array_key_exists('provided', $installed['versions'][$packageName])) {
$ranges = array_merge($ranges, $installed['versions'][$packageName]['provided']);
}

return implode(' || ', $ranges);
}

throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}





public static function getVersion($packageName)
{
foreach (self::getInstalled() as $installed) {
if (!isset($installed['versions'][$packageName])) {
continue;
}

if (!isset($installed['versions'][$packageName]['version'])) {
return null;
}

return $installed['versions'][$packageName]['version'];
}

throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}





public static function getPrettyVersion($packageName)
{
foreach (self::getInstalled() as $installed) {
if (!isset($installed['versions'][$packageName])) {
continue;
}

if (!isset($installed['versions'][$packageName]['pretty_version'])) {
return null;
}

return $installed['versions'][$packageName]['pretty_version'];
}

throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}





public static function getReference($packageName)
{
foreach (self::getInstalled() as $installed) {
if (!isset($installed['versions'][$packageName])) {
continue;
}

if (!isset($installed['versions'][$packageName]['reference'])) {
return null;
}

return $installed['versions'][$packageName]['reference'];
}

throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
}





public static function getRootPackage()
{
$installed = self::getInstalled();

return $installed[0]['root'];
}







public static function getRawData()
{
return self::$installed;
}



















public static function reload($data)
{
self::$installed = $data;
self::$installedByVendor = array();
}





private static function getInstalled()
{
if (null === self::$canGetVendors) {
self::$canGetVendors = method_exists('Composer\Autoload\ClassLoader', 'getRegisteredLoaders');
}

$installed = array();

if (self::$canGetVendors) {
foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
if (isset(self::$installedByVendor[$vendorDir])) {
$installed[] = self::$installedByVendor[$vendorDir];
} elseif (is_file($vendorDir.'/composer/installed.php')) {
$installed[] = self::$installedByVendor[$vendorDir] = require $vendorDir.'/composer/installed.php';
}
}
}

$installed[] = self::$installed;

return $installed;
}
}
