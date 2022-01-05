<?php

class SubstitutionEngine2
{
	protected $Cfg;

	function __construct(Cfg $Cfg)
	{
		$this->Cfg = $Cfg;
	}

	protected
	function unexpectedTokenException($token)
	{
		if (is_array($token))
			throw new \Exception(sprintf('unexpected token %s: "%s"', token_name($token[0]), $token[1]));
		else
			throw new \Exception(sprintf('unexpected token: "%s"', $token));
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

	function processStream(TUStream $Stream) : \Generator
	{
		$a = token_get_all($Stream->originalContent(), TOKEN_PARSE);
		for ($n = 0; $n < count($a); ++$n) {
			$token = $a[$n];

			switch ($token[0]) {
			case T_REQUIRE:
				$nn = 1;
				$ex = $this->expressionOf($a, $n+1);
				$tex = $ex;
				array_unshift($tex, $token);
				if ($this->constStringP($ex))
					yield from $this->processRequireConstString($Stream, $tex);
				else
					throw new \Exception(sprintf('Unsupported case: not a const string expression: "%s"',
						$this->expressionToString($tex) ));
				break;
			case T_OPEN_TAG:
				yield $token[1];
				break;
			default:
td(compact('token'));
			}
		}
	}
}
