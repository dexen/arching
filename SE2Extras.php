<?php

trait SE2Extras
{
	protected
	function devTdStatement(array $statement)
	{
		$type = $this->statementType($statement);
		if (is_array($statement[0]))
			$statement[0][0] = sprintf('%s (%d)', token_name($type), $type);
		else
			$statement[0] = [ sprintf('SINGULAR TOKEN (%s)', $type) ];
		td($statement);
	}

	protected
	function devTpStatement(array $statement)
	{
		$type = $this->statementType($statement);
		if (is_array($statement[0]))
			$statement[0][0] = sprintf('%s (%d)', token_name($type), $type);
		else
			$statement[0] = [ sprintf('SINGULAR TOKEN (%s)', $type) ];
		return tp($statement);
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
