<?php

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
TRACE('%% trying %s -> %s', $rpn, $pn);
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
			return yield from $this->inlineArchingInput($rpn);

		if ($rpn[0] === '/')
			throw new \RuntimeException('unsupported: absolute pathname');
		elseif ($rpn[0] === '.')
			return yield from $this->inlineAnIncludeByDirs(array_merge($this->Cfg->overrideDirs(), [$this->Cfg->scriptCwd()]), $rpn);
		elseif (strncmp($rpn, 'arching-', 8) === 0)
			return yield from $this->inlineAnIncludeByDirs($this->Cfg->archingIncludeDirs(), $rpn);
		else
			return yield from $this->inlineAnIncludeByDirs(
				array_merge($this->Cfg->overrideDirs(), $this->Cfg->projectIncludeDirs(),
					[dirname($InputTu->resolvedPathname()),$this->Cfg->scriptCwd()] ),
			$rpn );
	}
}
