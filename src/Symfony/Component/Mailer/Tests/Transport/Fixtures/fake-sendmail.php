#!/usr/bin/env php
<?php
$argsPath = getenv('SENDMAIL_ARGS_PATH');

file_put_contents($argsPath, implode(' ', $argv));
