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
	function processStreamOfStatements(TUStream $InputTu, array $G) : Generator
	{
		$ret = [];
		$anonymousOpened = false;
		foreach ($G as $statement)
			yield $statement;
/*
			if ($anonymousOpened) {
				if ($this->statementTypeP($statement, T_INCLUDE))
					throw new \Exception('unsupported case: an include');
				else if ($this->statementTypeP($statement, T_REQUIRE)) {
					$ex = $this->expressionOf($statement, 1);
						if ($this->constStringP($ex))
							yield from $this->processRequireConstString($InputTu, $statement, $ex);
						else
							throw new \Exception(sprintf('Unsupported case: not a const string expression: "%s"',
						$this->expressionToString($statement) )); }
				else
					yield $statement; }
			else if ($this->tokenTypeP($statement[0], T_OPEN_TAG)) {
				yield $statement;
				yield [ "\n", $this->genTokenNamespaceOpen(), "\n" ];
				$anonymousOpened = true; }
			else
				yield $statement;
		if ($this->statementTypeP($statement, T_CLOSE_TAG))
			yield [ '<?php', "\n", $this->genTokenNamespaceClose(), "\n" ];
		else
			yield [ "\n", $this->genTokenNamespaceClose(), "\n" ];
*/
	}

	protected
	function inlineAnInclude(TUStream $Stream) : \Generator
	{
		yield [ sprintf('# arching file require: \'%s\'; => %s ', $Stream->selector(), $Stream->resolvedPathname()) ];

		foreach (
			$this->processStreamOfStatements(
				$Stream,
				$this->statements(
					token_get_all($Stream->originalContent(), TOKEN_PARSE) ) ) as $statement)
			yield from $statement;
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
			switch ($this->tokenType($token)) {
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

	function processStream(TUStream $Stream) : \Generator
	{
		foreach (
			$this->processStreamOfStatements(
				$Stream,
				$this->statements(
					token_get_all($Stream->originalContent(), TOKEN_PARSE) ) ) as $statement)
			yield from $statement;
	}
}
