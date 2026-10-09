<?php
declare(strict_types=1);

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$root=realpath(__DIR__)?:__DIR__;
$relative=ltrim(rawurldecode($path),'/');
$topLevel=strtolower((string)strtok($relative,'/'));
$blockedDirectories=['app','config','database','storage','cron','tools','tests'];
$extension=strtolower((string)pathinfo($relative,PATHINFO_EXTENSION));
$initialInstaller = $relative === 'install.php' && !is_file(__DIR__ . '/config/local.php');

// The PHP built-in server bypasses Apache's .htaccess rules for real files.
// Enforce the same boundary here so a local preview cannot disclose secrets,
// SQL dumps, logs, or internal source files.
if (in_array($topLevel,$blockedDirectories,true) || (!$initialInstaller && in_array($extension,['php','sql','log','ini','env','bak'],true)) || str_starts_with(basename($relative),'.')) {
    http_response_code(404);
    exit;
}
$candidate=realpath(__DIR__.$path);
if($path!=='/' && $candidate!==false && str_starts_with($candidate,$root.DIRECTORY_SEPARATOR) && is_file($candidate)){
    return false;
}
require __DIR__.'/index.php';
