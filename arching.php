<?php

function td(...$a) { foreach ($a as $v) var_dump($v); die('td()'); }

function showHelp()
{
	echo "arching: package a PHP application into one file\n";
	echo "usage:\n";
	echo "	arching [ include_dir [ include_dir [ ... ]] [ -- ] ] file.php [ file.php [...] ] [ -o output.php ] [ --mkrule output.mk ] [ --source-map output.map ]\n";
	echo "	arching --apply-source-map output.map input.php [ -o output.php ]\n";
}

if (in_array($argv[1] ?? null, [ '-h', '--help']))
	die(showHelp());

class Cfg
{
	protected $argv;
	protected $project_include_dirs = [];
	protected $input_files = [];
	protected $output_pn = '/dev/stdout';
	protected $output_h;
	protected $mkrule_pn = '/dev/null';
	protected $mkrule_h;
	protected $apply_source_map;
	protected $apply_source_map_pn;

	function __construct(array $argv)
	{
		$this->argv = $argv;
		$a = array_slice($argv, 1);
		$collecting_dirs = true;
		while (($arg = array_shift($a)) !== null) {
			if ($collecting_dirs && ($arg === '-o'))
				$this->output_pn = array_shift($a);
			else if ($collecting_dirs && ($a === '--mkrule'))
				$this->mkrule_pn = array_shift($a);
			else if ($collecting_dirs && is_dir($arg))
				$this->project_include_dirs[] = $arg;
			else if ($collecting_dirs && ($arg === '--source-map'))
				$this->source_map_pn = array_shift($a);
			else if ($collecting_dirs && ($arg === '--apply-source-map')) {
				$this->apply_source_map_pn = array_shift($a);
				$this->apply_source_map = file_get_contents($this->apply_source_map_pn); }
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

	function archingIncludeDirs() : array { return [ sprintf('%s/%s', __DIR__, 'includes') ]; }

	function projectIncludeDir() : array { return $this->project_include_dirs; }

	function inputFiles() : array { return $this->input_files; }

	function sourceMapPN() : ?string { return $this->apply_source_map_pn; }

	function sourceMapToApply() : ?string { return $this->apply_source_map; }
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

class SyntaxCheckError extends ParseError
{
	function __construct(string $file, ParseError $Previous)
	{
		parent::__construct($Previous->getMessage(), $Previous->getCode(), $Previous);
		$this->file = $file;
		$this->line = $Previous->line;
	}
}

function expectCorrectPhpSyntax(string $code, string $file) : string
{
	try {
		token_get_all($code, TOKEN_PARSE); }
	catch (ParseError $E) {
		throw new SyntaxCheckError($file, $E); }
	return $code;
}

function inlineAnInclude(string $selector, string $pn) : string
{
	return sprintf('# arching file require: \'%s\'; => %s ', $selector, $pn) .expectCorrectPhpSyntax(file_get_contents($pn), $pn);
}

function inlineArchingInput(string $selector) : string
{
	return sprintf('# arching file require: \'%s\'; => %s ', $selector, 'STDIN') .expectCorrectPhpSyntax(stream_get_contents(STDIN), 'STDIN');
}

function substituteInclude(string $line) : string
{
	global $Cfg;

	if (!preg_match('/^require /', $line))
		return $line;

	$rpn = extractRequirePathname($line);

	if ($rpn === 'arching-input.php')
		return inlineArchingInput($rpn);

	if (strncmp($rpn, 'arching-', 8) === 0)
		$include_dirs = $Cfg->archingIncludeDirs();
	else
		$include_dirs = $Cfg->projectIncludeDir();

	foreach ($include_dirs as $dir) {
		$pn = sprintf('%s/%s', $dir, $rpn);
		if (file_exists($pn))
			return inlineAnInclude($rpn, $pn); }
	throw new \RuntimeException(sprintf('include file not found for "%s"', $rpn));
}

function applySourceMap(string $content) : string
{
	global $Cfg;

	$placeholder = 'placeholder:2780d720-9b9d-' .'4415-82a7-d9388630cba6' .';source map as PHP array contents';

	if ($Cfg->sourceMapToApply() === null)
		return $content;
	$a = json_decode($Cfg->sourceMapToApply(), $associative = true, $max_dept = 512, JSON_THROW_ON_ERROR);
	$mapstr = '';
	foreach ($a as $k => $v)
		$mapstr .= sprintf('%d=>[%s, %d], ', $k, var_export((string)$v[0], $return = true), $v[1]);

	return str_replace('/*' .$placeholder .'*/', $mapstr, $content);
}

foreach ($Cfg->inputFiles() as $in_pn) {
	expectCorrectPhpSyntax(file_get_contents($in_pn), $in_pn);
	$h = fopen($in_pn, 'r');
	while (($line = fgets($h)) !== false) {
		output(applySourceMap(substituteInclude($line))); } }
