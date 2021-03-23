<?php

function td(...$a) {
	$L = fn($str) => fputs(STDERR, $str ."\n");
	foreach ($a as $v) $L(var_export($v, true)); $L('td()'); die(1); }

ini_set('include_path', 'JUNK');

require './aa/bb/cc.php';
