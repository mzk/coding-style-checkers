<?php

declare(strict_types=1);

use Nette\CodeChecker\Result;
use Nette\CodeChecker\Tasks;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test(function () {
	$result = new Result;
	Tasks::jsonSyntaxChecker('true', $result);
	Assert::same([], $result->getMessages());
});

test(function () {
	$result = new Result;
	Tasks::jsonSyntaxChecker('{"a":1}', $result);
	Assert::same([], $result->getMessages());
});

test(function () {
	$result = new Result;
	Tasks::jsonSyntaxChecker('{"a":1', $result);
	Assert::count(1, $result->getMessages());
});

test(function () {
	$result = new Result;
	Tasks::jsonSyntaxChecker('', $result);
	Assert::count(1, $result->getMessages());
});
