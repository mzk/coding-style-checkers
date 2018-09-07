<?php

declare(strict_types=1);

namespace Nette\CodeChecker;

use Latte;
use Nette;
use Nette\Utils\Strings;


class Tasks
{
	public static function controlCharactersChecker(string $contents, Result $result): void
	{
		if (!Strings::match($contents, '#^[^\x00-\x08\x0B\x0C\x0E-\x1F]*+$#')) {
			$result->error('Contains control characters');
		}
	}


	public static function bomFixer(string &$contents, Result $result): void
	{
		if (substr($contents, 0, 3) === "\xEF\xBB\xBF") {
			$result->fix('contains BOM');
			$contents = substr($contents, 3);
		}
	}


	public static function utf8Checker(string $contents, Result $result): void
	{
		if (!Strings::checkEncoding($contents)) {
			$result->error('Is not valid UTF-8 file');
		}
	}


	public static function invalidPhpDocChecker(string $contents, Result $result): void
	{
		foreach (token_get_all($contents) as $token) {
			if ($token[0] === T_COMMENT && Strings::match($token[1], '#/\*(?!\*).*(?<!\w)@[a-z]#isA')) {
				$result->warning('Missing /** in phpDoc comment', $token[2]);

			} elseif ($token[0] === T_COMMENT && Strings::match($token[1], '#/\*\*(?!\s).*(?<!\w)@[a-z]#isA')) {
				$result->warning('Missing space after /** in phpDoc comment', $token[2]);
			}
		}
	}


	public static function shortArraySyntaxFixer(string &$contents, Result $result): void
	{
		$out = '';
		$brackets = [];
		$tokens = token_get_all($contents);

		for ($i = 0; $i < count($tokens); $i++) {
			$token = $tokens[$i];
			if ($token === '(') {
				$brackets[] = false;

			} elseif ($token === ')') {
				$token = array_pop($brackets) ? ']' : ')';

			} elseif (is_array($token) && $token[0] === T_ARRAY) {
				$a = $i + 1;
				if (isset($tokens[$a]) && $tokens[$a][0] === T_WHITESPACE) {
					$a++;
				}
				if (isset($tokens[$a]) && $tokens[$a] === '(') {
					$result->fix('uses old array() syntax', $token[2]);
					$i = $a;
					$brackets[] = true;
					$token = '[';
				}
			}
			$out .= is_array($token) ? $token[1] : $token;
		}
		$contents = $out;
	}


	public static function strictTypesDeclarationChecker(string $contents, Result $result): void
	{
		$declarations = '';
		$tokens = token_get_all($contents);
		for ($i = 0; $i < count($tokens); $i++) {
			if ($tokens[$i][0] === T_DECLARE) {
				while (isset($tokens[++$i]) && $tokens[$i] !== ';') {
					$declarations .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
				}
			} elseif (!in_array($tokens[$i][0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				break;
			}
		}
		if (!preg_match('#\bstrict_types\s*=\s*1\b#', $declarations)) {
			$result->error('Missing declare(strict_types=1)');
		}
	}


	public static function invalidDoubleQuotedStringChecker(string $contents, Result $result): void
	{
		$prev = null;
		foreach (token_get_all($contents) as $token) {
			if (($token[0] === T_ENCAPSED_AND_WHITESPACE && ($prev[0] !== T_START_HEREDOC || !strpos($prev[1], "'")))
				|| ($token[0] === T_CONSTANT_ENCAPSED_STRING && $token[1][0] === '"')
			) {
				$m = Strings::match($token[1], '#^([^\\\\]|\\\\[\\\\nrtvefxu0-7\W])*+#'); // more strict: '#^([^\\\\]|\\\\[\\\\nrtvefu$"x0-7])*+#'
				if ($token[1] !== $m[0]) {
					$result->warning('Invalid escape sequence ' . substr($token[1], strlen($m[0]), 2) . ' in double quoted string', $token[2]);
				}
			}
			$prev = $token;
		}
	}


	public static function newlineNormalizer(string &$contents, Result $result): void
	{
		$new = str_replace("\n", PHP_EOL, str_replace(["\r\n", "\r"], "\n", $contents));
		if ($new !== $contents) {
			$result->fix('contains non-system line-endings');
			$contents = $new;
		}
	}


	public static function trailingPhpTagRemover(string &$contents, Result $result): void
	{
		$tmp = rtrim($contents);
		if (substr($tmp, -2) === '?>') {
			$result->fix('contains closing PHP tag ?>');
			$contents = substr($tmp, 0, -2);
		}
	}


	public static function phpSyntaxChecker(string $contents, Result $result): void
	{
		$php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
		$stdin = tmpfile();
		fwrite($stdin, $contents);
		fseek($stdin, 0);
		$process = proc_open(
			$php . ' -l',
			[$stdin, ['pipe', 'w'], ['pipe', 'w']],
			$pipes,
			null, null, ['bypass_shell' => true]
		);
		if (!is_resource($process)) {
			$result->warning('Unable to lint generated template');
			return;
		}
		$error = stream_get_contents($pipes[1]);
		if (proc_close($process)) {
			$error = strip_tags(explode("\n", $error)[1]);
			$result->error('Invalid PHP code: ' . $error);
		}
	}


	public static function latteSyntaxChecker(string $contents, Result $result): void
	{
		$latte = new Latte\Engine;
		$latte->setLoader(new Latte\Loaders\StringLoader);
		$latte->getCompiler()->addMacro('cache', new Nette\Bridges\CacheLatte\CacheMacro);
		Nette\Bridges\ApplicationLatte\UIMacros::install($latte->getCompiler());
		Nette\Bridges\FormsLatte\FormMacros::install($latte->getCompiler());

		try {
			$code = $latte->compile($contents);
			static::phpSyntaxChecker($code, $result);

		} catch (Latte\CompileException $e) {
			if (!preg_match('#Unknown (macro|attribute)#A', $e->getMessage())) {
				$result->error($e->getMessage(), $e->sourceLine);
			} else {
				$result->warning($e->getMessage(), $e->sourceLine);
			}
		}
	}


	public static function neonSyntaxChecker(string $contents, Result $result): void
	{
		try {
			Nette\Neon\Neon::decode($contents);
		} catch (Nette\Neon\Exception $e) {
			$result->error($e->getMessage());
		}
	}


	public static function jsonSyntaxChecker(string $contents, Result $result): void
	{
		try {
			Nette\Utils\Json::decode($contents);
			if (trim($contents) === '') {
				$result->error('Syntax error');
			}
		} catch (Nette\Utils\JsonException $e) {
			$result->error($e->getMessage());
		}
	}


	public static function yamlIndentationChecker(string $contents, Result $result): void
	{
		if (preg_match('#^\t#m', $contents, $m, PREG_OFFSET_CAPTURE)) {
			$result->error('Used tabs to indent instead of spaces', self::offsetToLine($contents, $m[0][1]));
		}
	}


	public static function trailingWhiteSpaceFixer(string &$contents, Result $result): void
	{
		$new = Strings::replace($contents, '#[\t ]+(\r?\n)#', '$1'); // right trim
		$eol = preg_match('#\r?\n#', $new, $m) ? $m[0] : PHP_EOL;
		$new = rtrim($new); // trailing trim
		if ($new !== '') {
			$new .= $eol;
		}
		if ($new !== $contents) {
			$bytes = strlen($contents) - strlen($new);
			$result->fix("$bytes bytes of whitespaces");
			$contents = $new;
		}
	}


	public static function tabIndentationChecker(string $contents, Result $result, string $origContents = null): void
	{
		$origContents = $origContents ?: $contents;
		$offset = 0;
		if (preg_match('#^(\t*+)\ (?!\*)\s*#m', $contents, $m, PREG_OFFSET_CAPTURE)) {
			$result->error(
				$m[1][0] ? 'Mixed tabs and spaces to indent' : 'Used space to indent instead of tab',
				self::offsetToLine($origContents, $m[0][1])
			);
			$offset = $m[0][1] + strlen($m[0][0]) + 1;
		}
		if (preg_match('#(?<=[\S ])(?<!^//)\t#m', $contents, $m, PREG_OFFSET_CAPTURE, $offset)) {
			$result->error('Found unexpected tabulator', self::offsetToLine($origContents, $m[0][1]));
		}
	}


	public static function tabIndentationPhpChecker(string $contents, Result $result): void
	{
		$s = '';  // remove strings from code
		foreach (token_get_all($contents) as $token) {
			if (is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING], true)) {
				$token[1] = preg_replace('#\s#', '.', $token[1]);
			}
			$s .= is_array($token) ? $token[1] : $token;
		}
		self::tabIndentationChecker($s, $result, $contents);
	}


	public static function unexpectedTabsChecker(string $contents, Result $result): void
	{
		if (($pos = strpos($contents, "\t")) !== false) {
			$result->error('Found unexpected tabulator', self::offsetToLine($contents, $pos));
		}
	}


	private static function offsetToLine(string $s, int $offset): int
	{
		return $offset ? substr_count($s, "\n", 0, $offset) + 1 : 1;
	}
}