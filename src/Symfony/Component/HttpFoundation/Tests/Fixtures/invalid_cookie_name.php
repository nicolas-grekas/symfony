<?php

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/common.inc';

$r = new Response();
try{
    $r->headers->setCookie(new Cookie('Hello + world', 'hodor'));
} catch (\InvalidArgumentException $e){
    return null;
}
$r->sendHeaders();
