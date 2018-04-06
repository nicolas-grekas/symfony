<?php

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

$r = require __DIR__ . '/common.inc';

$r->headers->setCookie(new Cookie('https://blackfire.io/?*():@&+$/%#[]', 'https://blackfire.io/?*():@&+$/%#[]'));
$r->sendHeaders();
