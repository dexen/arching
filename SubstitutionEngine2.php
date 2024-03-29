<?php

class SubstitutionEngine2
{
	use SE2Extras;
	protected $namespaceOpen;
	const NAMESPACE_ANONYMOUS = -1;

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
			if (($this->namespaceOpen === null) && $this->tokenTypeP($statement[0], T_OPEN_TAG)) {
				yield $statement;
				yield [ "\n", $this->genTokenNamespaceOpen(), "\n" ];
				$this->namespaceOpen = static::NAMESPACE_ANONYMOUS;
				$anonymousOpened = true; }
			else if ($this->statementTypeP($statement, T_INCLUDE)) {
				$ex = $this->expressionOf($InputTu, $statement, 1);
				throw new \Exception('unsupported case: an include');
				if ($this->constStringP($ex))
					yield from $this->processRequireConstString($InputTu, $statement, $ex);
				else
					throw new \Exception(sprintf('Unsupported case: not a const string expression: "%s"',
						$this->expressionToString($statement) )); }
			else if ($this->statementTypeP($statement, T_REQUIRE)) {
				$ex = $this->expressionOf($InputTu, $statement, 1);
				if ($this->constStringP($ex))
					yield from $this->processRequireConstString($InputTu, $statement, $ex);
				else
					throw new \Exception(sprintf('Unsupported case: not a const string expression: "%s"',
						$this->expressionToString($statement) )); }
			else if (($this->statementFindFunctionCallToFunction($statement, ['file_get_contents'])) !== null) {
				list($function_name, $start, $length) = $this->statementFindFunctionCallToFunction($statement, ['file_get_contents']);
				$statement2 = $statement;
				array_splice(
					$statement2,
					$start,
					$length,
					$ex = $this->processInlineConstString($InputTu, $statement,
						$this->inlinedContentFunctionCall($InputTu, $statement, $start, $length, $function_name)));
				yield $statement2; }
			else
				yield $statement;

		if ($anonymousOpened) {
			if ($this->statementTypeP($statement, [ T_CLOSE_TAG, T_INLINE_HTML ]))
				yield [ '<?php', "\n", $this->genTokenNamespaceClose(), "\n" ];
			else
				yield [ "\n", $this->genTokenNamespaceClose(), "\n" ]; }
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
			yield $statement;
		if ($this->statementTypeP($statement, [ T_CLOSE_TAG, T_INLINE_HTML ]))
			yield [ "<?php\n" ];
	}

	protected
	function processInlineConstString(TUStream $InputTu, array $statement, array $ex) : array
	{
		if (count($ex) === 1)
			$rpn = $this->constStringParse($ex[0][1]);
		else
			$rpn = $this->constStringConcatenatedParse($ex);
#			throw new \Exception('not supported yet: string concatenation etc.');

		$token = $ex[0];

		if ($rpn[0] === '/')
			throw new \RuntimeException('unsupported: absolute pathname');
### FIXME: this should be a separate case
### FIXME: add path resolution
		elseif ($rpn[0] === '.') {
			$value = file_get_contents($rpn);
			return [ $this->stringToT_CONSTANT_ENCAPSED_STRING($value, $token[2]) ]; }
### FIXME: add path resolution
		else {
			$value = file_get_contents($rpn);
			return [ $this->stringToT_CONSTANT_ENCAPSED_STRING($value, $token[2]) ]; }
	}

	protected
	function processRequireConstString(TUStream $InputTu, array $statement, array $ex) : \Generator
	{
		if (count($ex) === 1)
			$rpn = $this->constStringParse($ex[0][1]);
		else
			$rpn = $this->constStringConcatenatedParse($ex);
#			throw new \Exception('not supported yet: string concatenation etc.');

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
					[dirname($InputTu->resolvedPathname()??''),$this->Cfg->scriptCwd()] ),
			$rpn );
	}

	protected
	function statements(array $a) : array
	{
		$ret = [];
		$st = [];
		foreach ($a as $token) {
			switch ($this->tokenType($token)) {
			case T_WHITESPACE:
				$st[] = $token;
				if (count($st) === 1) {
					$ret[] = $st;
					$st = []; }
				break;
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
