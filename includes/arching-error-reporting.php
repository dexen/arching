<?php

set_error_handler(function(int $errno, string $errstr, string $errfile = null, int $errline = null) {
	printf("%s: %s<br>\n", $errno, $errstr);
	echo "--<br>\n";

	$F = function(string $pathname) {
			# note, this will be the DIR of the *compiled script*
		$strip_prefix = __DIR__ .'/';
		if (strncmp($pathname, $strip_prefix, strlen($strip_prefix)) === 0)
			return substr($pathname, strlen($strip_prefix));
	};

	$a = debug_backtrace();
	foreach ($a as $frame)
		printf("%s:%s<br>\n", $F($frame['file']??'?'), $frame['line']??'?');

	echo "--<br>\n";
	echo "stopping due to an error\n";
	die(1);
});
