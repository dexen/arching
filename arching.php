<?php

function td(...$a) { foreach ($a as $v) var_dump($v); die('td()'); }

function showHelp()
{
	echo "arching: package a PHP application into one file\n";
	echo "usage:\n";
	echo "	arching [ include_dir [ include_dir [ ... ]] [ -- ] ] file.php [ file.php [...] ] [ -o output.php ] [ --mkrule output.mk ]\n";
}

if (in_array($argv[1] ?? null, [ '-h', '--help']))
	die(showHelp());

class Cfg
{
	protected $argv;
	protected $include_dirs = [];
	protected $input_files = [];
	protected $output_pn = '/dev/stdout';
	protected $output_h;
	protected $mkrule_pn = '/dev/null';
	protected $mkrule_h;

	function __construct(array $argv)
	{
		$this->argv = $argv;
		$a = array_slice($argv, 1);
		$collecting_dirs = true;
		while (($arg = array_shift($a)) !== null) {
			if ($arg === '-o')
				$this->output_pn = array_shift($a);
			else if ($a === '--mkrule')
				$this->mkrule_pn = array_shift($a);
			else if ($collecting_dirs && is_dir($arg))
				$this->include_dirs[] = $arg;
			else if ($arg === '--')
				$collecting_dirs = false;
			else if (is_file($arg)) {
				$collecting_dirs = false;
				$this->input_files[] = $arg; }
			else {
				throw new \RuntimeException(sprintf('unexpected argument: "%s"', $arg)); } }

		$this->output_h = $this->handleForPn($this->output_pn);
		$this->mkrule_h = $this->handleForPn($this->mkrule_pn);
	}

	protected
	function handleForPn(string $pn) {
		if ($pn === '/dev/stdout')
			return STDOUT;
		return fopen($pn, 'w');
	}

	function output(string $str) { fwrite($this->output_h, $str); }

	function outmkrule(string $str) { fwrite($this->output_h, $str); }

	function includeDirs() : array { return $this->include_dirs; }

	function inputFiles() : array { return $this->input_files; }
}

$Cfg = new Cfg($argv);

function output(...$a) { global $Cfg; foreach ($a as $str) $Cfg->output($str); }
function outl(...$a) { global $Cfg; foreach ($a as $str) $Cfg->output($str); $Cfg->output("\n"); }
function outputf($fmt, ...$a) { global $Cfg; $Cfg->output(sprintf($fmt, ...$a)); }
function outfl($fmt, ...$a) { global $Cfg; $Cfg->output(sprintf($fmt, ...$a)); $Cfg->output("\n"); }

function outmkrule(...$a) { global $Cfg; foreach ($a as $str) $Cfg->outmkrule($str); }

foreach ($Cfg->inputFiles() as $in_pn) {
	$h = fopen($in_pn, 'r');
	while (($line = fgets($h)) !== false)
		output($line);
}
