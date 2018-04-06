<?php

use Symfony\Component\HttpFoundation\Cookie;

/** @var \Symfony\Component\HttpFoundation\Response $r */
$r = require __DIR__ . '/common.inc';

$url = 'https://blackfire.io/?*():@&+$/%#[]';

$r->headers->setCookie(new Cookie($url, $url, 0, '/', null, false, false, true));
$r->sendHeaders();

setrawcookie($url, $url, 0, '/', null, false, false);
