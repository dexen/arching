<?php

function td(...$a) {
	$L = fn($str) => fputs(STDERR, $str ."\n");
	foreach ($a as $v) $L(var_export($v, true)); die('td()'); }
function tp(...$a) { foreach ($a as $v) var_export($v); echo('tp()'); return $a[0]??null; }

function showHelp()
{
	echo "arching: package a PHP application into one file\n";
	echo "usage:\n";
	echo "	arching [ include_dir [ include_dir [ ... ]] [ -- ] ] file.php [ file.php [...] ] [ -o output.php ] [ --mkrule output.mk ] [ --source-map output.map ]\n";
	echo "	arching --apply-source-map output.map input.php [ -o output.php ]\n";
}

class ProcessingFailedException extends RuntimeException {}

class IncludeNotFoundException extends ProcessingFailedException {}

class Cfg
{
	protected $argv;
	protected $project_include_dirs = [];
	protected $input_files = [];
	protected $output_pn = '/dev/stdout';
	protected $output_h;
	protected $mkrule_pn = '/dev/null';
	protected $mkrule_h;
	protected $source_map_pn;
	protected $apply_source_map;
	protected $apply_source_map_pn;
	protected $directives_to_process = ['require'];

	function __construct(array $argv)
	{
		$this->argv = $argv;
		$a = array_slice($argv, 1);
		$collecting_dirs = true;
		while (($arg = array_shift($a)) !== null) {
			if ($collecting_dirs && ($arg === '-o'))
				$this->output_pn = array_shift($a);
			else if ($collecting_dirs && ($arg === '--process-include'))
				$this->directives_to_process[] = 'include';
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

	function archingIncludeDirs() : array { return [ sprintf('%s/%s', __DIR__, 'includes') ]; }

	function projectIncludeDir() : array { return $this->project_include_dirs; }

	function inputFiles() : array { return $this->input_files; }

	function sourceMapToApplyPN() : ?string { return $this->apply_source_map_pn; }

	function sourceMapToApply() : ?string { return $this->apply_source_map; }

	function sourceMapPN() : ?string { return $this->source_map_pn; }

	function directivesToProcess() : array { return $this->directives_to_process; }
}


class SourceMap
{
	protected $Cfg;
	protected $list = [];

	function __construct(Cfg $Cfg) { $this->Cfg = $Cfg; }

	function noteRequire(string $file, int $fromOutputLine, int $numLines)
	{
		$this->list[] = [ $file, $fromOutputLine, $numLines ];
	}

	function asMap() : array
	{
		$ret = [];
			# FIXME
			# this assumes non-overlapping files
			# need adaptation for overlapping files (includes-in-includes)
		foreach ($this->list as $rcd)
			$ret[$rcd[1]] = [$rcd[0], $rcd[1]+$rcd[2]-1];
		return $ret;
	}

	function sourceMapOutput()
	{
		$v = file_put_contents(
			$this->Cfg->sourceMapPN(),
			json_encode($this->asMap(),
				 JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
		if ($v === false)
			throw new \RuntimeException('could not write source map');
	}

	function sourceMapHandleOutput()
	{
		if ($this->Cfg->sourceMapPN())
			$this->sourceMapOutput();
	}
}

class SubstitutionEngine
{
	protected $Cfg;

	function __construct(Cfg $Cfg)
	{
		$this->Cfg = $Cfg;
	}

	function processStream(TUStream $Stream) : Generator
	{
		$pre = '';
		foreach ($Stream->originalLines() as $line) {
			yield $pre;
			yield from $this->processOneLine($line, -1);
			$pre = "\n"; }
	}

	protected
	function substitutionRe() : string
	{
		$quote = fn($str) => preg_quote($str, '/');

		return '/^(' .implode('|', array_map($quote, $this->Cfg->directivesToProcess())) .')\\s+/';
	}

	protected
	function interpretQuotedString(string $str) : string
	{
		return stripcslashes(trim($str, "\"'"));
	}

	protected
	function inlineArchingInput(string $selector) : Generator
	{
		yield sprintf('# arching file require: \'%s\'; => %s ', $selector, 'STDIN') .expectCorrectPhpSyntax(stream_get_contents(STDIN), 'STDIN');
	}

	protected
	function extractRequirePathname($line) : string
	{
		$a = token_get_all('<?php ' .$line);
		while (($rcd = array_shift($a)) !== null) {
			switch ($rcd[0]) {
			case T_OPEN_TAG:
			case T_WHITESPACE:
				break;
			case T_INCLUDE:
			case T_REQUIRE:
				$rcd2 = array_shift($a);
				if ($rcd2[0] !== T_WHITESPACE)
					throw new \RuntimeException(sprintf('unexpected token "%s": "%s" (%s)', $rcd2[0], $rcd2[1], token_name($rcd2[0])));
				$rcd3 = array_shift($a);
				if ($rcd3[0] === T_CONSTANT_ENCAPSED_STRING)
					return $this->interpretQuotedString($rcd3[1]);
				else
					throw new \RuntimeException(sprintf('unexpected token "%s": "%s" (%s)', $rcd2[0], $rcd2[1], token_name($rcd2[0])));
			default:
				if (is_string($rcd))
					throw new \RuntimeException(sprintf('unexpected token "%s"; line: "%s"', $rcd, $line));
				else
					throw new \RuntimeException(sprintf('unexpected token "%s": "%s" (%s)', $rcd[0] ??null, $rcd[1] ?? null, token_name($rcd[0]))); } }
	}

	protected
	function inlineAnInclude(TUStream $TUS, $output_line_nr) : Generator
	{
		global $SourceMap;

#		$SourceMap->noteRequire($pn, $output_line_nr, count(explode("\n", file_get_contents($pn))));
		yield sprintf('# arching file require: \'%s\'; => %s ', $TUS->selector(), $TUS->resolvedPathname());

		$pre = '';
		foreach ($TUS->originalLines() as $line) {
			yield $pre;
			yield $line;
			$pre = "\n"; }
	}

	protected
	function processOneLine(string $line, int $output_line_nr) : Generator
	{
		if (!preg_match($this->substitutionRe(), $line))
			return yield $line;

		$rpn = $this->extractRequirePathname($line);

		if ($rpn === 'arching-input.php')
			return $this->inlineArchingInput($rpn);

		if (strncmp($rpn, 'arching-', 8) === 0)
			$include_dirs = $this->Cfg->archingIncludeDirs();
		else
			$include_dirs = $this->Cfg->projectIncludeDir();

		foreach ($include_dirs as $dir) {
			$pn = sprintf('%s/%s', $dir, $rpn);
			if (file_exists($pn))
				return yield from $this->inlineAnInclude(new TUStream(file_get_contents($pn), $rpn, $pn), $output_line_nr); }
		throw new IncludeNotFoundException(sprintf('include file not found for "%s"', $rpn));
	}
}

class TUStream
{
	protected $content_original;
	protected $selector;
	protected $resolved_pathname;

	function __construct(string $content_original, string $selector, string $resolved_pathname = null)
	{
		expectCorrectPhpSyntax($content_original, $selector);
		$this->content_original = $content_original;
		$this->selector = $selector;
		$this->resolved_pathname = $resolved_pathname;
	}

	function originalLines() : array { return explode("\n", $this->content_original); }

	function selector() : string { return $this->selector; }

	function resolvedPathname() : ?string { return $this->resolved_pathname; }
}

if (in_array($argv[1] ?? '--help', [ '-h', '--help']))
	die(showHelp());

$Cfg = new Cfg($argv);
$SourceMap = new SourceMap($Cfg);

function output(string $str) : int {
	static $line_nr = 0;
	global $Cfg;
	$Cfg->output($str);
	$line_nr += substr_count($str, "\n");
	return $line_nr;
}

function outputGenerator(Generator $G) { foreach ($G as $str) output($str); }

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

try {
	$internalA = ['<?php'];
	foreach ($Cfg->inputFiles() as $pn)
		$internalA[] = sprintf('require %s;', var_export($pn, true));

	$Internal = new TUStream(implode("\n", $internalA) ."\n", '<internal>');

	$SE = new SubstitutionEngine($Cfg);

	outputGenerator($SE->processStream($Internal));

	exit();

	$SourceMap->sourceMapHandleOutput(); }
catch (IncludeNotFoundException $E) {
	$L = function($str, ...$a) { if ($a) $str = sprintf($str, ...$a); fputs(STDERR, $str ."\n"); };

	$L($E->getMessage());
	$L('--');
	$L('Include path:');
	foreach ($Cfg->archingIncludeDirs() as $pn)
		$L('	%s', $pn);
	foreach ($Cfg->projectIncludeDir() as $pn)
		$L('	%s', $pn);
	die(1); }
