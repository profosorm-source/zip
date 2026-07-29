<?php

declare(strict_types=1);

namespace Core\Sql;

/**
 * SafeExpression — Allowlist-based SQL expression parser & emitter.
 *
 * Philosophy
 * ──────────
 * Instead of asking "is this string dangerous?" (a blacklist), we ask
 * "is this string a member of a tightly-defined safe sub-grammar?"
 * (an allowlist). The output is structurally guaranteed to be:
 *
 *   - read-only (no DDL, no DML, no DCL keywords parsed at all),
 *   - free of stacked statements (parser stops at the first `;`),
 *   - free of comments (the lexer rejects them),
 *   - free of dangerous functions (only a whitelist of names is callable),
 *   - free of subqueries (no SELECT/UNION/parenthesised statements),
 *   - parameter-safe (the only placeholder is `?`, passed through bindings).
 *
 * Grammar (EBNF, simplified)
 * ──────────────────────────
 *   top         := expression [ [ "AS" ] alias_ident ]
 *   expression  := or_expr
 *   or_expr     := and_expr ( ( "OR" )  and_expr )*
 *   and_expr    := not_expr ( ( "AND" ) not_expr )*
 *   not_expr    := [ "NOT" ] cmp_expr
 *   cmp_expr    := add_expr [ cmp_op  ( add_expr | "?" | NULL ) ]
 *               |  add_expr [ "IS" [ "NOT" ] NULL ]
 *               |  add_expr [ "BETWEEN" add_expr "AND" add_expr ]
 *               |  add_expr [ "LIKE" ( string | "?" ) ]
 *   add_expr    := mul_expr ( ( "+" | "-" ) mul_expr )*
 *   mul_expr    := unary    ( ( "*" | "/" | "%" ) unary )*
 *   unary       := [ "-" ] primary
 *   primary     := number
 *               |  string                            // single-quoted literal
 *               |  "?"                               // bound placeholder
 *               |  "NULL" | "TRUE" | "FALSE"
 *               |  identifier ( "." identifier )?    // col or table.col
 *               |  function_call
 *               |  case_expr
 *               |  "(" expression ")"
 *   function_call := whitelisted_name "(" [ "DISTINCT" ] arg_list? ")"
 *   arg_list    := expression ( "," expression )*
 *   case_expr   := "CASE" ( "WHEN" expression "THEN" expression )+
 *                  [ "ELSE" expression ] "END"
 *
 * NOT in the grammar (by design — silent reject):
 *   SELECT, UNION, INSERT, UPDATE, DELETE, DROP, ALTER, ...
 *   comments  (--, /* ... *​/, #),
 *   semicolons (;),
 *   subqueries,
 *   backslash escapes inside strings (use '' to escape a quote),
 *   nested SELECT in any form,
 *   `INTO OUTFILE`, `LOAD_FILE`, etc.
 *
 * Output
 * ──────
 * After successful parse, ->emit() returns a normalised, requoted SQL
 * fragment where every identifier is backtick-quoted and every literal
 * is properly escaped. This means the *output* string is what hits the
 * database, not the user's raw input — a structural guarantee.
 *
 * Usage
 * ─────
 *   $expr = SafeExpression::parse("SUM(CASE WHEN status='deleted' THEN 1 ELSE 0 END)");
 *   $sql  = $expr->emit();   // → SUM(CASE WHEN `status` = 'deleted' THEN 1 ELSE 0 END)
 *
 *   // With bindings (e.g. for whereRaw):
 *   $expr = SafeExpression::parse("end_date > ? AND status = ?", $allowedColumns);
 *
 * Function allowlist
 * ──────────────────
 * Only PURE, deterministic, READ-ONLY scalar functions are allowed. The list
 * intentionally excludes:
 *   - SLEEP, BENCHMARK, GET_LOCK, RELEASE_LOCK            (DoS / timing)
 *   - LOAD_FILE, INTO OUTFILE                              (file system)
 *   - USER, CURRENT_USER, DATABASE, VERSION, @@variables   (fingerprinting)
 *   - EXTRACTVALUE, UPDATEXML                              (error-based exfil)
 *   - REGEXP_*, RLIKE                                      (ReDoS surface)
 *   - any procedure/UDF
 */
final class SafeExpression
{
    /**
     * Whitelisted SQL function names. Lower-cased; matched case-insensitively.
     * Add to this list ONLY after a security review. Each entry must be:
     *   - deterministic (same inputs → same output),
     *   - read-only (no side effects, no I/O),
     *   - bounded (cannot be made to consume unbounded CPU/memory).
     */
    private const ALLOWED_FUNCTIONS = [
        // Aggregates
        'count', 'sum', 'avg', 'min', 'max',
        // Numeric
        'abs', 'ceil', 'ceiling', 'floor', 'round', 'mod', 'sign', 'truncate',
        'greatest', 'least', 'power', 'sqrt',
        // String (safe, bounded — no REGEXP family)
        'concat', 'concat_ws', 'length', 'char_length', 'lower', 'upper',
        'ltrim', 'rtrim', 'trim', 'substring', 'substr', 'left', 'right',
        'replace', 'lpad', 'rpad', 'reverse', 'locate', 'position', 'instr',
        // Date/time (no NOW with side-effect-y session vars)
        'now', 'curdate', 'curtime', 'current_date', 'current_time',
        'current_timestamp', 'unix_timestamp', 'from_unixtime',
        'date', 'time', 'year', 'month', 'day', 'hour', 'minute', 'second',
        'dayofweek', 'dayofyear', 'weekofyear', 'quarter',
        'date_add', 'date_sub', 'datediff', 'timediff', 'timestampdiff',
        'date_format', 'time_format', 'str_to_date',
        // NULL handling
        'coalesce', 'ifnull', 'nullif', 'isnull', 'if',
        // JSON read-only
        'json_extract', 'json_unquote', 'json_length', 'json_type',
        'json_contains', 'json_valid',
        // Casting (parameterised — see special handling)
        'cast', 'convert',
    ];

    private const COMPARISON_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', '<=>'];

    /** @var list<array{type:string,value:string,pos:int}> */
    private array $tokens;
    private int $pos = 0;
    private array $allowedColumns; // empty = allow any identifier shape (still backtick-quoted)
    private array $bindings = [];

    /** Root AST node after successful parse. */
    private array $ast;

    /** The original input — kept ONLY for error messages, never emitted. */
    private string $source;

    /**
     * Parse a raw expression string. Throws on any deviation from the grammar.
     *
     * @param string                  $sql              the user-provided expression
     * @param list<string>            $allowedColumns   optional whitelist of column names
     *                                                  (incl. "table.col"); empty = any
     * @return self                                     a parsed, ready-to-emit expression
     * @throws SqlExpressionException                   on lex / parse / policy failure
     */
    public static function parse(string $sql, array $allowedColumns = []): self
    {
        $self = new self();
        $self->source         = $sql;
        $self->allowedColumns = array_map('strtolower', $allowedColumns);
        $self->tokens         = $self->lex($sql);
        $self->ast            = $self->parseExpression();

        // BUGFIX-SAFEEXPR-AS-ALIAS-2026-06:
        // پشتیبانی از سینتکس استاندارد ` ... AS alias` در سطح top-level expression.
        // alias فقط یک identifier ساده مجاز است و نمی‌تواند keyword/reserved باشد.
        // فرم بدون AS هم پذیرفته می‌شود (مانند `COUNT(*) c`) چون SQL استاندارد آن را قبول دارد،
        // اما برای ایمنی حداقلی، حالت "implicit alias" را فقط وقتی قبول می‌کنیم که عبارت
        // واقعاً به یک IDENT خالی منتهی شود (تا با اشتباه syntactic مانند `a b` که می‌تواند
        // typo باشد، silent pass نشویم).
        $aliasTok = null;
        if ($self->matchKeyword('as')) {
            $aliasTok = $self->consume();
            if ($aliasTok['type'] !== 'IDENT') {
                $self->fail("expected alias identifier after AS, got '{$aliasTok['value']}'", $aliasTok['pos']);
            }
        } elseif ($self->peek()['type'] === 'IDENT'
                  && $self->peek(1)['type'] === 'EOF') {
            // implicit alias: only accept when EXACTLY one identifier is left before EOF.
            $aliasTok = $self->consume();
        }

        if ($aliasTok !== null) {
            $alias = $aliasTok['value'];
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $alias)) {
                $self->fail("invalid alias identifier '{$alias}'", $aliasTok['pos']);
            }
            // یک defense-in-depth کوچک: alias نباید با SQL reserved word اصلی برابر باشد.
            // این مانع اشتباه dev می‌شود (مثلاً `COUNT(*) as select`) که اگرچه با backtick
            // در MariaDB legal است، اما در کوئری‌های مصرف‌کننده‌ی خروجی ابهام‌ساز است.
            $reservedAliases = [
                'select','from','where','join','on','group','order','having','limit',
                'insert','update','delete','drop','alter','create','table','union',
                'as','and','or','not','null','true','false','case','when','then','else','end',
                'distinct','interval','is','in','like','between',
            ];
            if (in_array(strtolower((string)$alias), $reservedAliases, true)) {
                $self->fail("alias '{$alias}' is a reserved SQL keyword", $aliasTok['pos']);
            }
            $self->ast = ['kind' => 'aliased', 'expr' => $self->ast, 'alias' => $alias];
        }

        if ($self->peek()['type'] !== 'EOF') {
            $tok = $self->peek();
            $self->fail("unexpected token '{$tok['value']}' after expression", $tok['pos']);
        }
        return $self;
    }

    /** Emit the canonical, safely-quoted SQL fragment. */
    public function emit(): string
    {
        return $this->emitNode($this->ast);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEXER
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array{type:string,value:string,pos:int}> */
    private function lex(string $sql): array
    {
        if (strlen((string)$sql) > 4096) {
            $this->fail('expression exceeds maximum length (4096)', 0);
        }

        $tokens = [];
        $i = 0;
        $n = strlen((string)$sql);

        while ($i < $n) {
            $c = $sql[$i];

            // whitespace
            if (ctype_space($c)) { $i++; continue; }

            // hard-reject characters that have NO place in a safe expression
            if ($c === ';' || $c === '\\' || $c === '#' || $c === '@' || $c === '$' || $c === '`') {
                $this->fail("forbidden character '{$c}' in expression", $i);
            }

            // reject SQL comments early — they only exist to evade scanners
            if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-') {
                $this->fail('SQL comments are not allowed', $i);
            }
            if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
                $this->fail('SQL comments are not allowed', $i);
            }

            // single-quoted string literal (SQL standard: '' escapes ')
            if ($c === "'") {
                $start = $i;
                $i++;
                $value = '';
                $closed = false;
                while ($i < $n) {
                    if ($sql[$i] === "'") {
                        if ($i + 1 < $n && $sql[$i + 1] === "'") {
                            $value .= "'";
                            $i += 2;
                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    }
                    // explicitly forbid backslash escapes — surprising MySQL behaviour
                    if ($sql[$i] === '\\') {
                        $this->fail('backslash escapes are not allowed in string literals; use \'\' to escape a quote', $i);
                    }
                    $value .= $sql[$i];
                    $i++;
                }
                if (!$closed) $this->fail('unterminated string literal', $start);
                $tokens[] = ['type' => 'STRING', 'value' => $value, 'pos' => $start];
                continue;
            }

            // placeholder
            if ($c === '?') {
                $tokens[] = ['type' => 'PLACEHOLDER', 'value' => '?', 'pos' => $i];
                $i++;
                continue;
            }

            // numbers (int / decimal — no scientific notation, no hex)
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($sql[$i + 1]))) {
                $start = $i;
                $hasDot = false;
                while ($i < $n && (ctype_digit($sql[$i]) || (!$hasDot && $sql[$i] === '.'))) {
                    if ($sql[$i] === '.') $hasDot = true;
                    $i++;
                }
                $tokens[] = ['type' => 'NUMBER', 'value' => substr($sql, $start, $i - $start), 'pos' => $start];
                continue;
            }

            // identifiers / keywords (letters, digits, underscore; must start with letter or _)
            if (ctype_alpha($c) || $c === '_') {
                $start = $i;
                while ($i < $n && (ctype_alnum($sql[$i]) || $sql[$i] === '_')) $i++;
                $word = substr($sql, $start, $i - $start);
                $lower = strtolower((string)$word);

                // multi-word operators
                if (in_array($lower, ['and','or','not','is','null','true','false','between','like','in',
                                       'case','when','then','else','end','distinct','as','interval'], true)) {
                    $tokens[] = ['type' => 'KEYWORD', 'value' => $lower, 'pos' => $start];
                } else {
                    $tokens[] = ['type' => 'IDENT', 'value' => $word, 'pos' => $start];
                }
                continue;
            }

            // multi-char operators
            $two = $i + 1 < $n ? $sql[$i] . $sql[$i + 1] : '';
            $three = $i + 2 < $n ? $two . $sql[$i + 2] : '';
            if ($three === '<=>') { $tokens[] = ['type'=>'OP','value'=>'<=>','pos'=>$i]; $i += 3; continue; }
            if (in_array($two, ['<=','>=','!=','<>'], true)) {
                $tokens[] = ['type'=>'OP','value'=>$two,'pos'=>$i]; $i += 2; continue;
            }

            // single-char tokens
            if (in_array($c, ['(',')',',','+','-','*','/','%','=','<','>','.'], true)) {
                // '.' is its own punctuation (used for table.column qualification);
                // other punctuation '(' ')' ',' are also PUNCT; the rest are OP.
                $type = in_array($c, ['(',')',',','.'], true) ? 'PUNCT' : 'OP';
                $tokens[] = ['type' => $type, 'value' => $c, 'pos' => $i];
                $i++;
                continue;
            }

            $this->fail("unexpected character '{$c}'", $i);
        }

        $tokens[] = ['type' => 'EOF', 'value' => '', 'pos' => $n];
        return $tokens;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PARSER (recursive descent, with precedence climbing for binary ops)
    // ─────────────────────────────────────────────────────────────────────────

    private function parseExpression(): array { return $this->parseOr(); }

    private function parseOr(): array
    {
        $left = $this->parseAnd();
        while ($this->matchKeyword('or')) {
            $right = $this->parseAnd();
            $left  = ['kind' => 'logical', 'op' => 'OR', 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    private function parseAnd(): array
    {
        $left = $this->parseNot();
        while ($this->matchKeyword('and')) {
            $right = $this->parseNot();
            $left  = ['kind' => 'logical', 'op' => 'AND', 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    private function parseNot(): array
    {
        if ($this->matchKeyword('not')) {
            return ['kind' => 'not', 'expr' => $this->parseNot()];
        }
        return $this->parseComparison();
    }

    private function parseComparison(): array
    {
        $left = $this->parseAdd();

        // IS [NOT] NULL
        if ($this->matchKeyword('is')) {
            $negate = $this->matchKeyword('not');
            $this->expectKeyword('null');
            return ['kind' => 'is_null', 'expr' => $left, 'negate' => $negate];
        }

        // BETWEEN a AND b
        if ($this->matchKeyword('between')) {
            $lo = $this->parseAdd();
            $this->expectKeyword('and');
            $hi = $this->parseAdd();
            return ['kind' => 'between', 'expr' => $left, 'lo' => $lo, 'hi' => $hi];
        }

        // [NOT] LIKE
        if ($this->matchKeyword('like')) {
            $right = $this->parseAdd();
            return ['kind' => 'like', 'left' => $left, 'right' => $right, 'negate' => false];
        }

        // [NOT] IN (?, ?, ...)
        if ($this->matchKeyword('in')) {
            $this->expectPunct('(');
            $items = [];
            if ($this->peek()['value'] !== ')') {
                $items[] = $this->parseExpression();
                while ($this->matchPunct(',')) $items[] = $this->parseExpression();
            }
            $this->expectPunct(')');
            return ['kind' => 'in', 'expr' => $left, 'items' => $items, 'negate' => false];
        }

        // standard comparison
        if ($this->peek()['type'] === 'OP' && in_array($this->peek()['value'], self::COMPARISON_OPERATORS, true)) {
            $op = $this->consume()['value'];
            $right = $this->parseAdd();
            return ['kind' => 'cmp', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseAdd(): array
    {
        $left = $this->parseMul();
        while ($this->peek()['type'] === 'OP' && in_array($this->peek()['value'], ['+','-'], true)) {
            $op    = $this->consume()['value'];
            $right = $this->parseMul();
            $left  = ['kind' => 'arith', 'op' => $op, 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    private function parseMul(): array
    {
        $left = $this->parseUnary();
        while ($this->peek()['type'] === 'OP' && in_array($this->peek()['value'], ['*','/','%'], true)) {
            $op    = $this->consume()['value'];
            $right = $this->parseUnary();
            $left  = ['kind' => 'arith', 'op' => $op, 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    private function parseUnary(): array
    {
        if ($this->peek()['type'] === 'OP' && $this->peek()['value'] === '-') {
            $this->consume();
            return ['kind' => 'neg', 'expr' => $this->parseUnary()];
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $tok = $this->peek();

        // parenthesised expression
        if ($tok['type'] === 'PUNCT' && $tok['value'] === '(') {
            $this->consume();
            $inner = $this->parseExpression();
            $this->expectPunct(')');
            return ['kind' => 'paren', 'expr' => $inner];
        }

        // INTERVAL n unit  (only inside DATE_ADD / DATE_SUB / TIMESTAMPADD-style args)
        if ($tok['type'] === 'KEYWORD' && $tok['value'] === 'interval') {
            $this->consume();
            $n = $this->parseAdd();          // numeric expression
            $unit = $this->peek();
            // unit must be a bare IDENT from a fixed allowlist (matches MySQL units)
            $allowedUnits = [
                'microsecond','second','minute','hour','day','week','month','quarter','year',
                'second_microsecond','minute_microsecond','minute_second',
                'hour_microsecond','hour_second','hour_minute',
                'day_microsecond','day_second','day_minute','day_hour','year_month',
            ];
            if ($unit['type'] !== 'IDENT' || !in_array(strtolower($unit['value']), $allowedUnits, true)) {
                $this->fail("expected INTERVAL unit (e.g. DAY, HOUR), got '{$unit['value']}'", $unit['pos']);
            }
            $this->consume();
            return ['kind' => 'interval', 'value' => $n, 'unit' => strtoupper($unit['value'])];
        }

        // literals
        if ($tok['type'] === 'NUMBER')      { $this->consume(); return ['kind' => 'num', 'value' => $tok['value']]; }
        if ($tok['type'] === 'STRING')      { $this->consume(); return ['kind' => 'str', 'value' => $tok['value']]; }
        if ($tok['type'] === 'PLACEHOLDER') { $this->consume(); $this->bindings[] = '?'; return ['kind' => 'placeholder']; }

        if ($tok['type'] === 'KEYWORD') {
            if (in_array($tok['value'], ['null','true','false'], true)) {
                $this->consume();
                return ['kind' => 'const', 'value' => strtoupper($tok['value'])];
            }
            if ($tok['value'] === 'case') return $this->parseCase();
            if ($tok['value'] === 'distinct') {
                // bare DISTINCT only valid inside a function call (handled there)
                $this->fail("'DISTINCT' is only allowed inside a function call", $tok['pos']);
            }
        }

        if ($tok['type'] === 'IDENT') {
            $name = $this->consume()['value'];

            // function call?
            if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === '(') {
                return $this->parseFunctionCall($name, $tok['pos']);
            }

            // column reference, optionally "table.col"
            if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === '.') {
                $this->consume(); // dot
                $next = $this->peek();
                if ($next['type'] !== 'IDENT' && !($next['type'] === 'OP' && $next['value'] === '*')) {
                    $this->fail('expected identifier after "."', $next['pos']);
                }
                $col = $this->consume()['value'];
                return $this->makeColumn($name, $col, $tok['pos']);
            }
            return $this->makeColumn(null, $name, $tok['pos']);
        }

        $this->fail("unexpected token '{$tok['value']}'", $tok['pos']);
    }

    private function parseCase(): array
    {
        $this->expectKeyword('case');
        $branches = [];
        while ($this->matchKeyword('when')) {
            $cond = $this->parseExpression();
            $this->expectKeyword('then');
            $then = $this->parseExpression();
            $branches[] = ['when' => $cond, 'then' => $then];
        }
        if (!$branches) $this->fail('CASE expression requires at least one WHEN branch', $this->peek()['pos']);
        $else = null;
        if ($this->matchKeyword('else')) $else = $this->parseExpression();
        $this->expectKeyword('end');
        return ['kind' => 'case', 'branches' => $branches, 'else' => $else];
    }

    private function parseFunctionCall(string $name, int $pos): array
    {
        $lname = strtolower((string)$name);
        if (!in_array($lname, self::ALLOWED_FUNCTIONS, true)) {
            $this->fail("function '{$name}' is not in the allowlist", $pos);
        }

        $this->expectPunct('(');
        $distinct = false;
        $args = [];

        if ($this->peek()['value'] !== ')') {
            // DISTINCT only meaningful for COUNT/SUM/AVG, and only as first arg
            if ($this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === 'distinct') {
                if (!in_array($lname, ['count','sum','avg'], true)) {
                    $this->fail("DISTINCT not allowed in {$name}()", $this->peek()['pos']);
                }
                $this->consume();
                $distinct = true;
            }

            // COUNT(*) special-case
            if ($lname === 'count' && $this->peek()['type'] === 'OP' && $this->peek()['value'] === '*') {
                $this->consume();
                $this->expectPunct(')');
                return ['kind' => 'func', 'name' => $lname, 'distinct' => false, 'args' => [['kind' => 'star']]];
            }

            $args[] = $this->parseExpression();
            while ($this->matchPunct(',')) $args[] = $this->parseExpression();
        }
        $this->expectPunct(')');
        return ['kind' => 'func', 'name' => $lname, 'distinct' => $distinct, 'args' => $args];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EMITTER (canonical, safely-quoted output)
    // ─────────────────────────────────────────────────────────────────────────

    private function emitNode(array $n): string
    {
        switch ($n['kind']) {
            case 'num':         return $n['value'];
            case 'str':         return "'" . str_replace("'", "''", $n['value']) . "'";
            case 'placeholder': return '?';
            case 'const':       return $n['value'];
            case 'star':        return '*';
            case 'column':      return $n['table'] !== null
                                    ? "`{$n['table']}`.`{$n['name']}`"
                                    : "`{$n['name']}`";
            case 'paren':       return '(' . $this->emitNode($n['expr']) . ')';
            case 'neg':         return '-' . $this->emitNode($n['expr']);
            case 'not':         return 'NOT ' . $this->emitNode($n['expr']);
            case 'is_null':     return $this->emitNode($n['expr']) . ' IS ' . ($n['negate'] ? 'NOT ' : '') . 'NULL';
            case 'cmp':         return $this->emitNode($n['left']) . ' ' . $n['op'] . ' ' . $this->emitNode($n['right']);
            case 'arith':       return $this->emitNode($n['left']) . ' ' . $n['op'] . ' ' . $this->emitNode($n['right']);
            case 'logical':     return $this->emitNode($n['left']) . ' ' . $n['op'] . ' ' . $this->emitNode($n['right']);
            case 'between':     return $this->emitNode($n['expr']) . ' BETWEEN ' .
                                       $this->emitNode($n['lo']) . ' AND ' . $this->emitNode($n['hi']);
            case 'like':        return $this->emitNode($n['left']) . ' LIKE ' . $this->emitNode($n['right']);
            case 'in':
                $items = array_map(fn($x) => $this->emitNode($x), $n['items']);
                return $this->emitNode($n['expr']) . ' IN (' . implode(', ', $items) . ')';
            case 'case':
                $s = 'CASE';
                foreach ($n['branches'] as $b) {
                    $s .= ' WHEN ' . $this->emitNode($b['when']) . ' THEN ' . $this->emitNode($b['then']);
                }
                if ($n['else'] !== null) $s .= ' ELSE ' . $this->emitNode($n['else']);
                return $s . ' END';
            case 'func':
                $args = array_map(fn($x) => $this->emitNode($x), $n['args']);
                $prefix = $n['distinct'] ? 'DISTINCT ' : '';
                return strtoupper($n['name']) . '(' . $prefix . implode(', ', $args) . ')';
            case 'interval':
                return 'INTERVAL ' . $this->emitNode($n['value']) . ' ' . $n['unit'];
            case 'aliased':
                // BUGFIX-SAFEEXPR-AS-ALIAS-2026-06: alias در emit همیشه با AS صریح و backtick.
                return $this->emitNode($n['expr']) . ' AS `' . $n['alias'] . '`';
        }
        throw new SqlExpressionException("internal: unknown node kind '{$n['kind']}'");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeColumn(?string $table, string $col, int $pos): array
    {
        // identifier shape was already validated by the lexer; double-check anti-injection
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col) ||
            ($table !== null && $col !== '*' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) ||
            ($table !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table))) {
            $this->fail('invalid identifier', $pos);
        }

        // if an allowlist of columns is set, enforce it
        if (!empty($this->allowedColumns)) {
            $fqn = strtolower(($table !== null ? "$table." : '') . $col);
            $bare = strtolower((string)$col);
            if (!in_array($fqn, $this->allowedColumns, true) &&
                !in_array($bare, $this->allowedColumns, true)) {
                $this->fail("column '{$col}' is not in the allowlist", $pos);
            }
        }

        return $col === '*'
            ? ['kind' => 'star']
            : ['kind' => 'column', 'table' => $table, 'name' => $col];
    }

    private function peek(int $off = 0): array { return $this->tokens[$this->pos + $off]; }
    private function consume(): array          { return $this->tokens[$this->pos++]; }

    private function matchKeyword(string $kw): bool
    {
        if ($this->peek()['type'] === 'KEYWORD' && $this->peek()['value'] === $kw) {
            $this->consume();
            return true;
        }
        return false;
    }

    private function matchPunct(string $p): bool
    {
        if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === $p) {
            $this->consume();
            return true;
        }
        return false;
    }

    private function expectKeyword(string $kw): void
    {
        if (!$this->matchKeyword($kw)) {
            $t = $this->peek();
            $this->fail("expected '{$kw}', got '{$t['value']}'", $t['pos']);
        }
    }

    private function expectPunct(string $p): void
    {
        if (!$this->matchPunct($p)) {
            $t = $this->peek();
            $this->fail("expected '{$p}', got '{$t['value']}'", $t['pos']);
        }
    }

    /** @return never */
    private function fail(string $why, int $pos): void
    {
        throw new SqlExpressionException(
            "SafeExpression: {$why} at position {$pos} in [" . substr($this->source, 0, 200) . ']'
        );
    }
}
