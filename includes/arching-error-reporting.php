<?php

set_error_handler(function(int $errno, string $errstr, string $errfile = null, int $errline = null) {
	printf("%s: %s<br>\n", $errno, $errstr);
	echo "--<br>\n";

		# format: NR => [ FILE, NR ]
		# first line of mapped file => [ relative pathname, last line of mapped file ]
	$source_map = [/*placeholder:2780d720-9b9d-4415-82a7-d9388630cba6;source map as PHP array contents*/];

	$F = function(string $pathname) {
			# note, this will be the DIR of the *compiled script*
		$strip_prefix = __DIR__ .'/';
		if (strncmp($pathname, $strip_prefix, strlen($strip_prefix)) === 0)
			return substr($pathname, strlen($strip_prefix));
	};

	$RF = function(string $file, string $line) use($source_map) : string {
		if ($line === '?')
			return $file;

		foreach ($source_map as $from => $rcd)
			if (($from <= $line) && ($rcd[1] >= $line))
				return $rcd[0];

		return '?';
	};
	$RL = function(string $file, string $line) use($source_map) : string {
		if ($line === '?')
			return $line;

		foreach ($source_map as $from => $rcd)
			if (($from <= $line) && ($rcd[1] >= $line))
				return $line - $from;

		return '?';
	};

	$a = debug_backtrace();
	foreach ($a as $frame)
		printf("%s:%s [%s:%s]<br>\n",
			$RF($F($frame['file']??'?'), $frame['line']??'?'), $RL($F($frame['file']??'?'), $frame['line']??'?'),
			$F($frame['file']??'?'), $frame['line']??'?');

	echo "--<br>\n";
	echo "stopping due to an error\n";
	die(1);
});
