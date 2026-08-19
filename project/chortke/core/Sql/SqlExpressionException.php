<?php

declare(strict_types=1);

namespace Core\Sql;

/**
 * Raised by SafeExpression when a raw SQL fragment is rejected — either
 * because it fails to tokenise, fails to parse against the safe sub-grammar,
 * or references something outside the function / column allowlist.
 *
 * Catch this if you want to surface "your raw SQL was rejected" to a developer
 * without leaking parser internals to end-users.
 */
final class SqlExpressionException extends \InvalidArgumentException
{
}
