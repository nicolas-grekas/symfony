<?php

use Symfony\Component\HttpFoundation\Cookie;

$r = require __DIR__ . '/common.inc';

$r->headers->setCookie(new Cookie('foo', 'bar', 946749600, '', null, false, false));
$r->sendHeaders();

setcookie('foo', 'bar', 946749600, '/');
