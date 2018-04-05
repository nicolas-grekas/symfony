<?php

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/common.inc';

$r = new Response();
$r->headers->set('Date', 'Thu, 02 Feb 2012 12:12:12 GMT');
$r->headers->setCookie(new Cookie('SameSite', 'Strict'));
$r->sendHeaders();
