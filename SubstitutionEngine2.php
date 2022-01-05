<?php

class SubstitutionEngine2
{
	protected $Cfg;

	function __construct(Cfg $Cfg)
	{
		$this->Cfg = $Cfg;
	}

	protected
	function unexpectedTokenException($token, $extra = null)
	{
		if (is_array($token))
			throw new \Exception(sprintf('unexpected token %s: "%s" ' .$extra, token_name($token[0]), $token[1]));
		else
			throw new \Exception(sprintf('unexpected token: "%s" ' .$extra, $token));
	}

	protected
	function expressionOf(array $tokens, $start)
	{
		$a = [];

		for ($n = $start; $n < count($tokens); ++$n) {
			$t = $tokens[$n];

			if (is_array($t))
				$ttype = $t[0];
			else
				$ttype = $t;

			switch ($t[0]) {
			case T_WHITESPACE:
				break;
			case T_CONSTANT_ENCAPSED_STRING:
			case T_VARIABLE:
				$a[] = $t;
				break;
			case ';':
				break 2;
			default:
				$this->unexpectedTokenException($t); } }

		return $a;
	}

	protected
	function tokenTypeP($token, $type) : bool
	{
		if (is_array($token))
			return $token[0] === $type;
		else
			return $token === $type;
	}

	protected
	function constStringP(array $tokens) : bool
	{
		if (count($tokens) === 1)
			if ($this->tokenTypeP($tokens[0], T_CONSTANT_ENCAPSED_STRING))
				return true;
			# FIXME - handle const string concatenation too
		return false;
	}

	protected
	function expressionToString(array $tokens)
	{
		return implode(array_map(fn($t)=>is_array($t)?$t[1]:$t, $tokens));
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
	function constStringParse(string $encoded) : string
	{
		if ($encoded[0] === '\'')
			return stripslashes(
				substr($encoded, 1, strlen($encoded)-2) );
		throw new \Exception(sprintf('unsupported case: not a single-quoted string: "%s"', $encoded));
	}

	protected
	function inlineAnInclude(TUStream $TUS) : \Generator
	{
		global $SourceMap;

#		$SourceMap->noteRequire($pn, $output_line_nr, count(explode("\n", file_get_contents($pn))));
		yield sprintf('# arching file require: \'%s\'; => %s ', $TUS->selector(), $TUS->resolvedPathname());

		foreach ($this->statements(token_get_all($TUS->originalContent(), TOKEN_PARSE)) as $statement)
			yield from $this->processOneStatement($TUS, $statement);
	}

	protected
	function processRequireConstString(TUStream $InputTu, array $statement, array $ex) : \Generator
	{
		if (count($ex) === 1)
			$rpn = $this->constStringParse($ex[0][1]);
		else
			throw new \Exception('not supported yet: string concatenation etc.');

		if ($rpn === 'arching-input.php')
			return yield from $this->inlineArchinInput($rpn);

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

	protected
	function statements(array $a) : array
	{
		$ret = [];
		$st = [];
		foreach ($a as $token) {
			if (is_array($token))
				$ttype = $token[0];
			else
				$ttype = $token;

			switch ($ttype) {
			case T_OPEN_TAG:
				if ($st !== [])
					$this->unexpectedTokenException($token, 'inside statement');
				$st[] = $token;
				$ret[] = $st;
				$st = [];
				break;
			case ';':
				$st[] = $token;
				$ret[] = $st;
				$st = [];
				break;
			default:
				$st[] = $token; } }
		if ($st)
			$ret[] = $st;
		return $ret;
	}

	function processOneStatement(TUStream $InputTu, array $statement) : \Generator
	{
		if (is_array($statement[0]))
			$ttype = $statement[0][0];
		else
			$ttype = $statement[0];

		switch ($ttype) {
		case T_OPEN_TAG:
		default:
			yield from $statement;
			break;
		case T_INCLUDE:
			throw new \Exception('unsupported case: an include');
		case T_REQUIRE:
			$ex = $this->expressionOf($statement, 1);
			if ($this->constStringP($ex))
				yield from $this->processRequireConstString($InputTu, $statement, $ex);
			else
				throw new \Exception(sprintf('Unsupported case: not a const string expression: "%s"',
					$this->expressionToString($statement) ));
			break; }
	}

	function processStream(TUStream $Stream) : \Generator
	{
		foreach ($this->statements(token_get_all($Stream->originalContent(), TOKEN_PARSE)) as $statement)
			yield from $this->processOneStatement($Stream, $statement);
	}
}
