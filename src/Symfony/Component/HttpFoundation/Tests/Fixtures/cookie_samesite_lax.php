<?php

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/common.inc';

$r = new Response();
$r->headers->set('Date', 'Sat, 12 Nov 1955 20:04:00 GMT');
$r->headers->setCookie(new Cookie('CookieSamesiteLaxTest', 'LaxValue', 0, '/', null, false, true, false, Cookie::SAMESITE_LAX));
$r->sendHeaders();
