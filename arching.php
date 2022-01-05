<?php

function td(...$a) {
	$L = fn($str) => fputs(STDERR, $str ."\n");
	foreach ($a as $v) $L(var_export($v, true)); $L('td()'); die(1); }
function tp(...$a) { foreach ($a as $v) var_export($v); echo('tp()'); return $a[0]??null; }

function TRACE(string $str, ...$a) {
	global $Cfg;

	if (!$Cfg->traceP())
		return;

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
	protected $script_cwd;
	protected $trace = false;

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
			else if ($collecting_dirs && ($arg === '--trace'))
				$this->trace = true;
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

	function scriptCwd(string $cwd = null) : string { if ($cwd !== null) $this->script_cwd = $cwd; return $this->script_cwd; }

	function traceP() : bool { return $this->trace; }
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

require __DIR__ .'/' .'SubstitutionEngine.php';
require __DIR__ .'/' .'SubstitutionEngine2.php';

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

	function originalContent() : string { return $this->content_original; }

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

					foreach (array_merge($Cfg->overrideDirs(), [$Cfg->scriptCwd()]) as $dir) {
						$pn = sprintf('%s/%s', $dir, $apn);
#TRACE('	trying "%s"', $pn);
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

function outputGenerator(Generator $G) {
	foreach ($G as $token)
		if (is_string($token))
			output($token);
		else if (is_array($token))
			output($token[1]);
		else
			throw new \Exception('token neither string nor array');
}

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
	$SE = new SubstitutionEngine2($Cfg);
	if ($SE instanceof SubstitutionEngine2)
		true;
	else
		output("<?php\n");

	foreach ($Cfg->inputFiles() as $pn) {
		$Cfg->scriptCwd(dirname($pn));
		if ($SE instanceof SubstitutionEngine2)
			$line = sprintf("<?php\nrequire %s;", var_export(basename($pn), true));
		else
			$line = sprintf('require %s;', var_export(basename($pn), true));
		outputGenerator($SE->processStream(new TUStream($line, $pn)));
	}

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
