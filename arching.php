<?php

function td(...$a) {
	$L = fn($str) => fputs(STDERR, $str ."\n");
	foreach ($a as $v) $L(var_export($v, true)); $L('td()'); die(1); }
function tp(...$a) { foreach ($a as $v) var_export($v); echo('tp()'); return $a[0]??null; }

function TRACE(string $str, ...$a) {
	if (count($a))
		$str = sprintf($str, ...$a);
	fputs(STDERR, $str);
	fputs(STDERR, "\n");
}

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
	protected $override_dirs = [];
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
	protected $include_transforms = [];
	protected $inline_files_when = [];
	protected $inline_files_separator = ';';

	function __construct(array $argv)
	{
		$this->argv = $argv;
		$a = array_slice($argv, 1);
		$collecting_dirs = true;
		while (($arg = array_shift($a)) !== null) {
			if ($collecting_dirs && ($arg === '-o'))
				$this->output_pn = array_shift($a);

				# example use:
				# --transform-include '#include "../adminer/lang/[$]LANG.inc.php";#' 'include "../adminer/lang/en.inc.php";'
			else if ($collecting_dirs && ($arg === '--transform-include'))
				$this->include_transforms[] = [ array_shift($a), array_shift($a) ];

				# frankly this should be moved to a separate, external tool
				# example use:
				# --inline-files-when "#lzw_decompress[(]compile_file[(]('../adminer/static/default.css;../externals/jush/jush.css'), 'minify_css'[)][)];#"
				# uses regular expression with capturing parentheses to extract file pathnames
				# uses semicolon to separate file pathnames
			else if ($collecting_dirs && ($arg === '--inline-files-when'))
				$this->inline_files_when[] = array_shift($a);

			else if ($collecting_dirs && ($arg === '--process-include'))
				$this->directives_to_process[] = 'include';
			else if ($collecting_dirs && ($arg === '--override-dir'))
				$this->override_dirs[] = array_shift($a);
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

	function overrideDirs() : array { return $this->override_dirs; }

	function projectIncludeDirs() : array { return $this->project_include_dirs; }

	function inputFiles() : array { return $this->input_files; }

	function sourceMapToApplyPN() : ?string { return $this->apply_source_map_pn; }

	function sourceMapToApply() : ?string { return $this->apply_source_map; }

	function sourceMapPN() : ?string { return $this->source_map_pn; }

	function directivesToProcess() : array { return $this->directives_to_process; }

	function includeTransforms() : array { return $this->include_transforms; }

	function inlineFilesWhen() : array { return $this->inline_files_when; }

	function inlineFilesSeparator() : string { return $this->inline_files_separator; }
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
			yield from $this->processOneLine($Stream, $line);
			$pre = "\n"; }
	}

	protected
	function substitutionRe() : string
	{
		$quote = fn($str) => preg_quote($str, '/');

		return '/^[ 	]*(' .implode('|', array_map($quote, $this->Cfg->directivesToProcess())) .')\\s+/';
	}

	protected
	function inlineArchingInput(string $selector) : Generator
	{
		yield sprintf('# arching file require: \'%s\'; => %s ', $selector, 'STDIN') .expectCorrectPhpSyntax(stream_get_contents(STDIN), 'STDIN');
	}

	protected
	function onUnexpectedToken(/*string|array*/ $token, string $line)
	{
		if (is_string($token))
			throw new \RuntimeException(sprintf('unexpected token "%s"; line: "%s"', $token, $line));
		else
			throw new \RuntimeException(sprintf('unexpected token %s: "%s"; line: "%s"', token_name($token[0]), $token[1], $line));
	}

	protected
	function parsedToPattern(array $a, string $line) : array
	{
		$ret = [];

		if ($a[0][0] === T_OPEN_TAG)
			array_shift($a);
		else
			$this->onUnexpectedToken($a[0], $line);

		foreach ($a as $rcd) {
			if (is_string($rcd))
				switch ($rcd) {
				case ';':
					return $ret;
				default:
					$this->onUnexpectedToken($rcd, $line); }
			else
				switch ($rcd[0]) {
				case T_REQUIRE:
				case T_INCLUDE:
				case T_CONSTANT_ENCAPSED_STRING:
					$ret[] = $rcd;
				case T_WHITESPACE:
					break;
				default:
					$this->onUnexpectedToken($rcd, $line); } }

		throw new \RuntimeException(sprintf('expected end-of-statement not found (semicolon ";"), line: "%s"', $line));
	}

	protected
	function onNoMatchingPatterns(array $aa, string $line)
	{
		$tA = array_map(
			fn($rcd) => is_string($rcd) ? $rcd : token_name($rcd[0]),
			$aa );
		throw new \RuntimeException(sprintf('tokens don\'t match any known pattern: [%s]; line "%s"', implode(', ', $tA), $line));
	}

	protected
	function patternMatchP(array $aa, array $pattern, int $capture)
	{
		$ret = true;

		if (count($aa) !== count($pattern))
			return null;

		$a = array_values($aa);
		$p = array_values($pattern);
		foreach ($a as $n => $rcd) {
			if ($rcd[0] !== $p[$n])
				return null;
			else if ($n === $capture)
				$ret = $rcd; }
		return $ret;
	}

	protected
	function applyTransformations(string $line) : string
	{
		$ret = $line;

		foreach ($this->Cfg->includeTransforms() as list($regex, $rep))
			$ret = preg_replace($regex, $rep, $ret);

		return $ret;
	}

	protected
	function extractRequirePathname($line) : string
	{
		$line = $this->applyTransformations($line);

		$a = token_get_all('<?php ' .$line);

		$aa = $this->parsedToPattern($a, $line);

		if ($rcd = $this->patternMatchP($aa, [T_REQUIRE, T_CONSTANT_ENCAPSED_STRING], 1))
			return interpretQuotedString($rcd[1]);
		else if ($rcd = $this->patternMatchP($aa, [T_INCLUDE, T_CONSTANT_ENCAPSED_STRING], 1))
			return interpretQuotedString($rcd[1]);
		else
			$this->onNoMatchingPatterns($aa, $line);

		while (($rcd = array_shift($a)) !== null) {
			switch ($rcd[0]) {
			case T_OPEN_TAG:
			case T_WHITESPACE:
				break;
			case T_INCLUDE:
			case T_REQUIRE:
				$rcd2 = array_shift($a);
				if ($rcd2[0] !== T_WHITESPACE)
					$this->onUnexpectedToken($rcd2, $line);
				$rcd3 = array_shift($a);
				if ($rcd3[0] === T_CONSTANT_ENCAPSED_STRING)
					return interpretQuotedString($rcd3[1]);
				else
					$this->onUnexpectedToken($rcd3, $line);
			default:
				$this->onUnexpectedToken($rcd, $line); } }
	}

	protected
	function inlineAnInclude(TUStream $TUS) : Generator
	{
		global $SourceMap;

#		$SourceMap->noteRequire($pn, $output_line_nr, count(explode("\n", file_get_contents($pn))));
		yield sprintf('# arching file require: \'%s\'; => %s ', $TUS->selector(), $TUS->resolvedPathname());

		$pre = '';
		foreach ($TUS->originalLines() as $line) {
			yield $pre;
			yield from $this->processOneLine($TUS, $line);
			$pre = "\n"; }
	}

	protected
	function inlineAnIncludeByDirs(array $include_dirs, string $rpn)
	{
		foreach ($include_dirs as $dir) {
			if ($dir === '.')
				$pn = $rpn;
			else
				$pn = sprintf('%s/%s', $dir, $rpn);
			if (file_exists($pn))
				return yield from $this->inlineAnInclude(new TUStream(file_get_contents($pn), $rpn, $pn)); }
		throw new IncludeNotFoundException(sprintf('include file not found for "%s"', $rpn));
	}

	protected
	function processOneLine(TUStream $InputTu, string $line) : Generator
	{
		if (!preg_match($this->substitutionRe(), $line))
			return yield $line;

		$rpn = $this->extractRequirePathname($line);

		if ($rpn === 'arching-input.php')
			return $this->inlineArchingInput($rpn);

		if ($rpn[0] === '/')
			throw new \RuntimeException('unsupported: absolute pathname');
		elseif ($rpn[0] === '.')
			return yield from $this->inlineAnIncludeByDirs([dirname($InputTu->resolvedPathname())], $rpn);
		elseif (strncmp($rpn, 'arching-', 8) === 0)
			return yield from $this->inlineAnIncludeByDirs($this->Cfg->archingIncludeDirs(), $rpn);
		else
			return yield from $this->inlineAnIncludeByDirs($this->Cfg->projectIncludeDirs(), $rpn);
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

function outputProcessing(string $string) : string
{
	global $Cfg;

	return array_reduce(
		$Cfg->inlineFilesWhen(),
		function(string $str, string $reg) use($Cfg) : string
		{

			$CB = function($match) use($Cfg) {
				$the_string = $match[1];
				$datastrings = [];

				foreach (explode($Cfg->inlineFilesSeparator(), interpretQuotedString($the_string)) as $apn) {
#fprintf(STDERR, 'processing "%s"' .PHP_EOL, $apn);
					$data = null;

					foreach ($Cfg->projectIncludeDirs() as $dir) {
						$pn = sprintf('%s/%s', $dir, $apn);
#fprintf(STDERR, '	trying "%s"' .PHP_EOL, $pn);
						if (file_exists($pn)) {
							$data = file_get_contents($pn);
							$datastrings[] = var_export($data, true);
						break; } }
					if ($data === null)
						throw new \RuntimeException(sprintf('file not found to inline, selector "%s"', $apn)); }
				return implode(' .', $datastrings);
			};

			return preg_replace_callback($reg, $CB, $str);
		},
		$string );
}

function output(string $str) : int {
	static $line_nr = 0;
	global $Cfg;

	$str = outputProcessing($str);

	$Cfg->output($str);
	$line_nr += substr_count($str, "\n");
	return $line_nr;
}

function outputGenerator(Generator $G) { foreach ($G as $str) output($str); }

function interpretQuotedString(string $str) : string
{
	return stripcslashes(trim($str, "\"'"));
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

	$Internal = new TUStream(implode("\n", $internalA), '<internal>', '.');

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
	foreach ($Cfg->projectIncludeDirs() as $pn)
		$L('	%s', $pn);
	die(1); }
