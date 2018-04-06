<?php

use Symfony\Component\HttpFoundation\Cookie;

$r = require __DIR__ . '/common.inc';

$r->headers->setCookie(new Cookie('https://blackfire.io/?*():@&+$/%#[]', 'https://blackfire.io/?*():@&+$/%#[]'));
$r->sendHeaders();
