<?php
declare(strict_types=1);

/**
 * Condition: a WHERE clause that is assembled from objects, never from SQL text.
 *
 * Part of the DBStore query layer, see {@see DBStore::findPaged()}. Used by the Datatable component
 * ({@see \App\Models\Helpers\Elements\Datatable\Datatable}) but independent of it.
 */

namespace MasterPuffin\DBStore;

use Closure;
use InvalidArgumentException;

/**
 * An immutable tree of comparisons that compiles to a parameterized SQL WHERE clause.
 *
 * Building one
 * ------------
 * Leaves are single comparisons ({@see Condition::where()}), inner nodes join their children with AND
 * ({@see Condition::all()}) or OR ({@see Condition::any()}). Nodes can be nested to any sensible depth.
 *
 * ```php
 * $condition = Condition::all(
 *     Condition::where('active', '=', true),
 *     Condition::any(
 *         Condition::where('name', 'contains', 'admin'),
 *         Condition::where('id', 'between', 1, 10),
 *     ),
 * );
 * // compiles to:  (`active` = ? AND (`name` LIKE ? ESCAPE '|' OR `id` BETWEEN ? AND ?))
 * // with values:  [1, '%admin%', 1, 10]
 * ```
 *
 * Operators
 * ---------
 * The operator names are the ones DataTables SearchBuilder uses, so its filters can be mapped one to one
 * ({@see \App\Models\Helpers\Elements\Datatable\DatatableRequest}).
 *
 * | Operator                  | Values | SQL                                                                     |
 * |---------------------------|--------|-------------------------------------------------------------------------|
 * | `=` `<` `<=` `>` `>=`     | 1      | plain comparison                                                        |
 * | `!=`                      | 1      | `<>`, and also matches NULL (see below)                                 |
 * | `contains` `starts` `ends`| 1      | `LIKE`; `%` and `_` in the value are literal characters                 |
 * | `!contains` `!starts` `!ends` | 1  | `NOT LIKE`, and also matches NULL                                       |
 * | `between`                 | 2      | inclusive range                                                         |
 * | `!between`                | 2      | outside the range, and also matches NULL                                |
 * | `null` / `!null`          | 0      | `IS NULL` / `IS NOT NULL`                                               |
 *
 * NULL handling: in SQL `NULL <> 'x'` is not true, so a naive "not equal" would silently drop rows whose
 * column is NULL. The negated operators therefore add `OR column IS NULL`, which is what users expect from
 * "does not contain". The positive operators never match NULL.
 *
 * Empty groups: `all()` without children is always true (`1=1`), `any()` without children is never true
 * (`1=0`), the usual identities of AND and OR.
 *
 * Why this is safe against SQL injection
 * --------------------------------------
 *  - Operators are looked up in a fixed table ({@see Condition::OPERATORS}); unknown ones are rejected when
 *    the condition is created.
 *  - Property names are never written into the SQL directly. {@see Condition::compile()} hands each one to a
 *    callback that DBStore implements; it only accepts real properties of the model and returns the quoted
 *    column ({@see DBStore::column()}).
 *  - Values are never part of the SQL text; they are returned separately and bound as parameters. For
 *    LIKE the wildcard characters of the value are escaped, with `|` as escape character.
 *  - The nesting depth is limited ({@see Condition::MAX_DEPTH}), so a hostile, deeply nested structure cannot
 *    exhaust the stack.
 */
final class Condition {
	/**
	 * The allowed operators and how many values each takes.
	 *
	 * @var array<string, int>
	 */
	private const OPERATORS = [
		'=' => 1, '!=' => 1, '<' => 1, '<=' => 1, '>' => 1, '>=' => 1,
		'contains' => 1, '!contains' => 1, 'starts' => 1, '!starts' => 1, 'ends' => 1, '!ends' => 1,
		'between' => 2, '!between' => 2,
		'null' => 0, '!null' => 0,
	];

	/**
	 * The deepest allowed nesting of groups.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 16;

	/**
	 * Use the static factories {@see Condition::all()}, {@see Condition::any()} and {@see Condition::where()}
	 * to create conditions. The constructor is private so that every instance has been validated.
	 *
	 * One class represents two kinds of node: a group (glue `AND` or `OR`, with children) and a leaf
	 * (glue empty, with property, operator and values).
	 *
	 * @param 'AND'|'OR'|'' $glue     `''` = a single comparison (leaf); otherwise the children are joined with it.
	 * @param Condition[]   $children Sub-conditions of a group.
	 * @param string        $property Model property name of a leaf.
	 * @param string        $operator Operator of a leaf, one of {@see Condition::OPERATORS}.
	 * @param array         $values   Values of a leaf, already checked against the operator's value count.
	 */
	private function __construct(
		private readonly string $glue,
		private readonly array  $children = [],
		private readonly string $property = '',
		private readonly string $operator = '',
		private readonly array  $values = [],
	) {
	}

	/**
	 * A group that matches when ALL given conditions match (AND). Without any conditions it always matches.
	 *
	 * @param Condition ...$conditions The sub-conditions.
	 *
	 * @return self
	 */
	public static function all(self ...$conditions): self {
		return new self('AND', $conditions);
	}

	/**
	 * A group that matches when AT LEAST ONE of the conditions matches (OR). Without any conditions it never
	 * matches.
	 *
	 * @param Condition ...$conditions The sub-conditions.
	 *
	 * @return self
	 */
	public static function any(self ...$conditions): self {
		return new self('OR', $conditions);
	}

	/**
	 * A single comparison of one model property with one or two values.
	 *
	 * Booleans are converted to 1 and 0, because that is how they are stored. The property name is not checked
	 * here but when the condition is compiled, since only the model knows its properties.
	 *
	 * @param string                  $property Name of a model PROPERTY (not a database column name).
	 * @param string                  $operator One of the operators in the class documentation.
	 * @param int|float|string|bool   ...$values As many values as the operator takes: 0 for `null`/`!null`,
	 *                                           2 for `between`/`!between`, otherwise 1.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException The operator is unknown, or the number of values does not match it.
	 */
	public static function where(string $property, string $operator, int|float|string|bool ...$values): self {
		if (self::valueCount($operator) !== count($values)) {
			throw new InvalidArgumentException('Operator "' . $operator . '" is unknown or got the wrong number of values');
		}
		return new self('', [], $property, $operator, array_map(fn($v) => is_bool($v) ? (int)$v : $v, $values));
	}

	/**
	 * How many values an operator takes. Also serves as the check whether an operator exists, which is how
	 * the request validation of untrusted SearchBuilder input uses it.
	 *
	 * @param string $operator The operator name.
	 *
	 * @return int|null 0, 1 or 2; null if the operator does not exist.
	 */
	public static function valueCount(string $operator): ?int {
		return self::OPERATORS[$operator] ?? null;
	}

	/**
	 * Builds the SQL for this condition, with `?` placeholders for all values.
	 *
	 * Groups are compiled recursively and wrapped in parentheses, so precedence is always explicit. The values
	 * of all children are concatenated in the same order in which their placeholders appear in the SQL, which is
	 * what lets the caller bind them positionally.
	 *
	 * @param Closure(string): string $column Turns a property name into a quoted column name. DBStore passes a
	 *                                        callback that throws for anything that is not a model property.
	 * @param int                     $depth  Current nesting depth. Callers leave it at 0; it is used by the
	 *                                        recursion.
	 *
	 * @return array{0: string, 1: array} The SQL fragment and the values to bind, in placeholder order.
	 *
	 * @throws InvalidArgumentException The nesting exceeds {@see Condition::MAX_DEPTH}, or `$column` rejected
	 *                                  a property name.
	 */
	public function compile(Closure $column, int $depth = 0): array {
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('Condition is nested too deeply');
		}

		// Group: compile the children and join them
		if ($this->glue !== '') {
			if ($this->children === []) {
				return [$this->glue === 'AND' ? '1=1' : '1=0', []];
			}
			$sql = [];
			$params = [];
			foreach ($this->children as $child) {
				[$childSql, $childParams] = $child->compile($column, $depth + 1);
				$sql[] = $childSql;
				array_push($params, ...$childParams);
			}
			return ['(' . implode(' ' . $this->glue . ' ', $sql) . ')', $params];
		}

		// Leaf: one comparison
		$col = $column($this->property);
		$v = $this->values;
		$text = isset($v[0]) ? self::escapeLike((string)$v[0]) : '';
		$like = " LIKE ? ESCAPE '|'"; // '|' is the escape character, see escapeLike()
		$notLike = " NOT LIKE ? ESCAPE '|'";

		return match ($this->operator) {
			'=', '<', '<=', '>', '>=' => [$col . ' ' . $this->operator . ' ?', $v],
			'!=' => ["($col <> ? OR $col IS NULL)", $v],
			'between' => ["$col BETWEEN ? AND ?", $v],
			'!between' => ["($col NOT BETWEEN ? AND ? OR $col IS NULL)", $v],
			'contains' => [$col . $like, ['%' . $text . '%']],
			'!contains' => ["($col$notLike OR $col IS NULL)", ['%' . $text . '%']],
			'starts' => [$col . $like, [$text . '%']],
			'!starts' => ["($col$notLike OR $col IS NULL)", [$text . '%']],
			'ends' => [$col . $like, ['%' . $text]],
			'!ends' => ["($col$notLike OR $col IS NULL)", ['%' . $text]],
			'null' => [$col . ' IS NULL', []],
			'!null' => [$col . ' IS NOT NULL', []],
		};
	}

	/**
	 * Makes `%` and `_` literal for use in a LIKE pattern, so that a user who searches for `50%` finds "50%"
	 * and not everything that starts with "50".
	 *
	 * The escape character is `|` and not the usual backslash, because the meaning of backslash inside
	 * string literals depends on the server's SQL mode, while `|` is the same everywhere. The escape
	 * character itself is doubled first.
	 *
	 * @param string $text The raw user text.
	 *
	 * @return string The text, safe to place between `%` wildcards of a LIKE pattern.
	 */
	private static function escapeLike(string $text): string {
		return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $text);
	}
}
