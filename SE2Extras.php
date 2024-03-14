<?php

trait SE2Extras
{
	protected
	function devTdStatement(array $statement)
	{
		foreach ($statement as $token) {
			if (is_array($token))
				$token[0] = sprintf('%s (%d)', token_name($token[0]), $this->tokenType($token));
			else
				$token =  [ sprintf('SINGULAR TOKEN (%s)', $this->tokenType($token)) ];
			$a[] = $token;
		}

		td($a);
	}

	protected
	function devTpStatement(array $statement)
	{
		foreach ($statement as $token) {
			if (is_array($token))
				$token[0] = sprintf('%s (%d)', token_name($type), $type);
			else
				$token =  [ sprintf('SINGULAR TOKEN (%s)', $type) ];
			$a[] = $token;
		}

		return tp($a);
	}

	protected
	function statementType(array $statement)
	{
		$ret = null;
		foreach ($statement as $token)
			if (($this->tokenType($token) === T_WHITESPACE) && ($ret !== null))
				continue;
			else if ($ret === null)
				$ret = $this->tokenType($token);
		return $ret;
	}

	protected
	function statementFunctionCallLocation(array $statement, int $start) : array
	{
		$length = 0;
		$paren_level = 0;
		$found_parens = false;
		foreach ($statement as $n => $token) {
			if ($n < $start)
				continue;
			++$length;
			if ($this->tokenType($token) === '(') {
				$found_parens = true;
				++$paren_level; }
			else if ($this->tokenType($token) === ')')
				--$paren_level;
			if (($found_parens) && ($paren_level <= 0)) {
				break; }
		}
		return [ $start, $length ];
	}

	protected
	function statementFindFunctionCallToFunction(array $statement, array $relevantFunctions) : ?array
	{
		foreach ($statement as $n => $token) {
			$type = $this->tokenType($token);
			$ntoken = $statement[$n+1] ?? null;
			$ntype = $this->tokentype($ntoken);
			if (($type === T_STRING) && ($ntype === '('))
				if (in_array($function_name = $token[1], $relevantFunctions, $strict = true)) {
					list($start, $length) = $this->statementFunctionCallLocation($statement, $n);
					return [ $function_name, $start, $length]; } }
		return null;
	}

	protected
	function inlinedContentFunctionCall(TUStream $InputTu, array $statement, int $start, int $length, string $function_name) : array
	{
		$a = array_slice($statement, $start, $length);
		$aa = array_slice($statement, $start+2, $length-3);
		return $this->expressionOf($InputTu, $aa, 0);
	}

	protected
	function statementTypeP(array $statement, $type) : bool
	{
		foreach ($statement as $token)
			if ($this->tokenTypeP($token, $type))
				return true;
			else if ($this->tokenTypeP($token, T_WHITESPACE))
				continue;
			else
				break;
		return false;
	}

	protected
	function tokenType($token)
	{
		if (is_array($token))
			return $token[0];
		else
			return $token;
	}

	protected
	function tokenTypeP($token, $type) : bool
	{
		if (is_array($type)) {
			if (is_array($token))
				return in_array($token[0], $type, true);
			else
				return in_array($token, $type, true); }

		if (is_array($token))
			return $token[0] === $type;
		else
			return $token === $type;
	}

	protected
	function expressionOf(TUStream $InputTu, array $tokens, $start)
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
			case T_DIR:
				$a[] = $this->stringToT_CONSTANT_ENCAPSED_STRING($InputTu->resolvedDir(), $t[2]);
				break;
			case '.':
				$a[] = '.';
				break;
			default:
				$this->unexpectedTokenException($t); } }

		return $a;
	}

	protected
	function stringToT_CONSTANT_ENCAPSED_STRING(string $string, int $line=null) : array
	{
		return [
			T_CONSTANT_ENCAPSED_STRING,
			var_export($string, true),
			$line,
		];
	}

	protected
	function constStringP(array $tokens) : bool
	{
		if (count($tokens) === 1)
			if ($this->tokenTypeP($tokens[0], T_CONSTANT_ENCAPSED_STRING))
				return true;
			# FIXME - handle const string concatenation too
		foreach ($tokens as $token)
			if ($this->tokenTypeP($token, T_CONSTANT_ENCAPSED_STRING))
				;
			else if ($this->tokenTypeP($token, '.'))
				;
			else
				return false;
		return true;
	}

	function genTokenNamespaceOpen()
	{
		return "namespace {";
	}

	function genTokenNamespaceClose()
	{
		return "}";
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
	function expressionToString(array $tokens)
	{
		return implode(array_map(fn($t)=>is_array($t)?$t[1]:$t, $tokens));
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
	function constStringConcatenatedParse(array $tokens) : string
	{
		$v = '';

		foreach ($tokens as $t) {
			if ($t === '.')
				;
			else if ($t[0] === T_CONSTANT_ENCAPSED_STRING)
				$v .= $this->constStringParse($t[1]);
			else
				throw new \Exception(sprintf('unsupported token type: "%s"', $this->tokenType($t))); }

		return $v;
	}
}
