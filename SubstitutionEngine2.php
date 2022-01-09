<?php

class SubstitutionEngine2
{
	use SE2Extras;

	protected $Cfg;

	function __construct(Cfg $Cfg)
	{
		$this->Cfg = $Cfg;
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
	function processStreamOfStatements(array $G) : array
	{
		$ret = [];
		$anonymousOpened = false;
		foreach ($G as $statement)
			if ($anonymousOpened)
				$ret[] = $statement;
			else if ($this->tokenTypeP($statement[0], T_OPEN_TAG)) {
				$ret[] = $statement;
				$ret[] = [ "\n", $this->genTokenNamespaceOpen(), "\n" ];
				$anonymousOpened = true; }
		if ($this->statementTypeP($ret[count($ret)-1], T_CLOSE_TAG))
			$ret[] = [ '<?php', "\n", $this->genTokenNamespaceClose(), "\n" ];
		else
			$ret[] = [ "\n", $this->genTokenNamespaceClose(), "\n" ];

		return $ret;
	}

	protected
	function inlineAnInclude(TUStream $TUS) : \Generator
	{
		global $SourceMap;

		yield sprintf('# arching file require: \'%s\'; => %s ', $TUS->selector(), $TUS->resolvedPathname());

		foreach (
			$this->processStreamOfStatements(
				$this->statements(token_get_all(
					$TUS->originalContent(), TOKEN_PARSE) ) ) as $statement)
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
		switch ($this->statementType($statement)) {
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
