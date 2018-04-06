<?php

use Symfony\Component\HttpFoundation\Cookie;

$r = require __DIR__ . '/common.inc';

$url = 'https://blackfire.io/?*():@&+$/%#[]';

$r->headers->setCookie(new Cookie($url, $url, 0, '', null, false, false));
$r->sendHeaders();

setcookie($url,$url, 0, '/');
