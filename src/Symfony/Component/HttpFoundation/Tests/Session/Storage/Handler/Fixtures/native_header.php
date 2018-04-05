<?php

require __DIR__.'/common.inc';

$res = new \Symfony\Component\HttpFoundation\Response();
$res->headers->set('Set-Cookie', 'key=value; path=/; domain=example.org; HttpOnly; SameSite=Strict');
$res->headers->set('Date', 'Thu, 05 Apr 2018 12:12:12 GMT');
$res->sendHeaders();
