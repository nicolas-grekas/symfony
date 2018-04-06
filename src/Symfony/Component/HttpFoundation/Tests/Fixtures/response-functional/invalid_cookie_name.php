<?php

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

$r = require __DIR__ . '/common.inc';

try{
    $r->headers->setCookie(new Cookie('Hello + world', 'hodor'));
} catch (\InvalidArgumentException $e){
    return null;
}
$r->sendHeaders();
