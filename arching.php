<?php

function showHelp()
{
	echo "arching: package a PHP application into one file\n";
	echo "usage:\n"
	echo "	arching [ include_dir [ include_dir [ ... ]]] file.php [ file.php [...] ] [ -o output.php ] [ --mkrule output.mk ]\n";
}

if (in_array($argv[1] ?? null, [ '-h', '--help']))
	die(showHelp());
