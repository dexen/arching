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

function interpretQuotedString(string $str) : string
{
	return stripcslashes(trim($str, "\"'"));
}

function extractRequirePathname(string $line) : string
{
	$a = token_get_all('<?php ' .$line);
	while (($rcd = array_shift($a)) !== null) {
		switch ($rcd[0]) {
		case T_OPEN_TAG:
		case T_WHITESPACE:
			break;
		case T_REQUIRE:
			$rcd2 = array_shift($a);
			if ($rcd2[0] !== T_WHITESPACE)
				throw new \RuntimeException(sprintf('unexpected token "%s": "%s" (%s)', $rcd2[0], $rcd2[1], token_name($rcd2[0])));
			$rcd3 = array_shift($a);
			if ($rcd3[0] === T_CONSTANT_ENCAPSED_STRING)
				return interpretQuotedString($rcd3[1]);
			else
				throw new \RuntimeException(sprintf('unexpected token "%s": "%s" (%s)', $rcd2[0], $rcd2[1], token_name($rcd2[0])));
		default:
			throw new \RuntimeException(sprintf('unexpected token "%s": "%s" (%s)', $rcd[0], $rcd[1], token_name($rcd[0]))); } }
}

function inlineAnInclude(string $selector, string $pn) : string
{
	return sprintf('# arching file require: \'%s\'; => %s ', $selector, $pn) .file_get_contents($pn);
}

function substituteInclude(string $line) : string
{
	global $Cfg;

	if (!preg_match('/^require /', $line))
		return $line;

	$rpn = extractRequirePathname($line);
	foreach ($Cfg->includeDirs() as $dir) {
		$pn = sprintf('%s/%s', $dir, $rpn);
		if (file_exists($pn))
			return inlineAnInclude($rpn, $pn);
#td(compact('line', 'rpn', 'dir', 'pn'));
	}
}

foreach ($Cfg->inputFiles() as $in_pn) {
	$h = fopen($in_pn, 'r');
	while (($line = fgets($h)) !== false) {
		output(substituteInclude($line));
	}
}
