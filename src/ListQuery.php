<?php
declare(strict_types=1);

/**
 * ListQuery: one page of a list, loaded with a single SQL query over a table and its joined relations.
 *
 * Part of the DBStore query layer, see {@see DBStore::findPaged()} and {@see DBStore::listQuery()}.
 */

namespace MasterPuffin\DBStore;

use Closure;
use InvalidArgumentException;
use SQL;

/**
 * Pages, filters, sorts and counts the rows of a FROM clause with joins, without hydrating model objects.
 *
 * For lists whose model would be too slow to hydrate row by row, e.g. because it loads its relations: the
 * names of the relations are joined instead. A model provides its list query by overriding
 * {@see DBStore::listQuery()}.
 *
 * ```php
 * public static function listQuery(): ListQuery {
 *     return new ListQuery(
 *         from: 'FROM `characters` c LEFT JOIN `users` u ON u.id = c.user',
 *         fields: [
 *             ...ListQuery::columns('c', 'id', 'nanoId', 'name'),
 *             'userName' => 'u.`name`',
 *         ],
 *         mapRow: fn(array $row) => (object)['id' => (int)$row['id'], ...],
 *     );
 * }
 * ```
 *
 * Fields
 * ------
 * `$fields` maps a field name to its SQL expression. It is at once the SELECT list (`expression AS name`) and
 * the whitelist of names that conditions and sort criteria may use: any other name is rejected, the same
 * injection protection as {@see DBStore::column()}. Field names and expressions come from the code, never
 * from a request.
 */
final class ListQuery {
	/**
	 * Upper limit for the page size, whatever the caller asks for. A last line of defence against code that
	 * passes an unchecked page size; the Datatable component caps the size much lower itself.
	 *
	 * @var int
	 */
	public const MAX_PAGE_SIZE = 1000;

	/**
	 * @param string                       $from   The FROM clause including `FROM` and the joins.
	 * @param array<string, string>        $fields Field name => SQL expression, see the class documentation.
	 * @param (Closure(array): object)|null $mapRow Turns a row (field name => raw value, exactly the fields) into
	 *                                             the returned object, e.g. to cast values. Null = the row as a
	 *                                             plain object.
	 */
	public function __construct(
		private readonly string   $from,
		private readonly array    $fields,
		private readonly ?Closure $mapRow = null,
	) {
	}

	/**
	 * Fields for columns of the main table that are selected under their own names.
	 *
	 * @param string $alias      The table alias of the FROM clause, e.g. `b`.
	 * @param string ...$columns The column names.
	 *
	 * @return array<string, string> Column => `` alias.`column` ``, to spread into the fields.
	 */
	public static function columns(string $alias, string ...$columns): array {
		return array_combine($columns, array_map(fn(string $column) => $alias . '.' . self::quote($column), $columns));
	}

	/**
	 * Loads one page of rows, like {@see DBStore::findPaged()}.
	 *
	 * @param Condition|null                          $where   The filter on the fields. Null = no filter.
	 * @param array<int, array{0: string, 1: string}> $orderBy Sort criteria as `[field, 'asc'|'desc']`.
	 * @param int                                     $limit   Rows per page, 1 to {@see ListQuery::MAX_PAGE_SIZE}.
	 * @param int                                     $offset  Rows to skip, 0 or more.
	 *
	 * @return object[] The rows, mapped by `$mapRow`.
	 *
	 * @throws InvalidArgumentException An unknown field, an invalid sort direction, or limit/offset out of range.
	 */
	public function find(?Condition $where, array $orderBy, int $limit, int $offset = 0): array {
		self::checkPage($limit, $offset);
		[$whereSql, $params] = self::compileWhere($where, $this->column(...));
		$select = implode(', ', array_map(fn(string $name, string $expression) => $expression . ' AS ' . self::quote($name),
			array_keys($this->fields), $this->fields));

		$sql = 'SELECT ' . $select . ' ' . $this->from . $whereSql
			. self::orderSql($orderBy, $this->column(...))
			. ' LIMIT ? OFFSET ?';
		$rows = self::run(true, $sql, [...$params, $limit, $offset]) ?? [];
		// The SQL class also returns every value under its numeric index
		$rows = array_map(fn(array $row) => array_intersect_key($row, $this->fields), $rows);
		return array_map($this->mapRow ?? fn(array $row) => (object)$row, $rows);
	}

	/**
	 * Counts the rows matching a condition, like {@see DBStore::countWhere()}.
	 *
	 * @param Condition|null $where The filter on the fields. Null counts all rows.
	 *
	 * @return int The number of matching rows.
	 *
	 * @throws InvalidArgumentException An unknown field.
	 */
	public function count(?Condition $where = null): int {
		[$whereSql, $params] = self::compileWhere($where, $this->column(...));
		$row = self::run(false, 'SELECT COUNT(*) AS cnt ' . $this->from . $whereSql, $params);
		return is_array($row) ? (int)($row['cnt'] ?? 0) : 0;
	}

	/**
	 * The SQL expression of a field: the gate through which every name in a condition or sort criterion passes.
	 *
	 * @throws InvalidArgumentException Not a field of this query.
	 */
	private function column(string $field): string {
		return $this->fields[$field] ?? throw new InvalidArgumentException('Unknown field ' . $field);
	}

	/**
	 * @throws InvalidArgumentException `$limit` or `$offset` out of range.
	 *
	 * @internal Shared with DBStore.
	 */
	public static function checkPage(int $limit, int $offset): void {
		if ($limit < 1 || $limit > self::MAX_PAGE_SIZE || $offset < 0) {
			throw new InvalidArgumentException('Invalid limit or offset');
		}
	}

	/**
	 * Compiles an optional condition into a WHERE clause.
	 *
	 * @param Condition|null          $where  The filter, or null for none.
	 * @param Closure(string): string $column Turns a name into its quoted column or expression, or throws.
	 *
	 * @return array{0: string, 1: array} The SQL fragment including the leading ` WHERE ` (empty string without a
	 *                                    condition), and the values to bind in placeholder order.
	 *
	 * @internal Shared with DBStore.
	 */
	public static function compileWhere(?Condition $where, Closure $column): array {
		if ($where === null) {
			return ['', []];
		}
		[$sql, $params] = $where->compile($column);
		return [' WHERE ' . $sql, $params];
	}

	/**
	 * Compiles sort criteria into an ORDER BY clause.
	 *
	 * @param array<int, array{0: string, 1: string}> $orderBy Sort criteria as `[name, 'asc'|'desc']`.
	 * @param Closure(string): string                 $column  Turns a name into its quoted column or expression, or throws.
	 *
	 * @return string The clause including the leading ` ORDER BY `, empty string without criteria.
	 *
	 * @throws InvalidArgumentException A sort direction other than asc/desc.
	 *
	 * @internal Shared with DBStore.
	 */
	public static function orderSql(array $orderBy, Closure $column): string {
		$order = [];
		foreach ($orderBy as [$name, $direction]) {
			$order[] = $column($name) . match ($direction) {
					'asc' => ' ASC',
					'desc' => ' DESC',
					default => throw new InvalidArgumentException('Invalid sort direction'),
				};
		}
		return $order ? ' ORDER BY ' . implode(', ', $order) : '';
	}

	/**
	 * Quotes an identifier (table or column name) with backticks and doubles any backtick inside it, so the
	 * name cannot end the quoting early. Defence in depth: names are already whitelisted before they get here.
	 *
	 * @param string $identifier The name to quote.
	 *
	 * @return string The quoted name.
	 *
	 * @internal Shared with DBStore.
	 */
	public static function quote(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	/**
	 * Runs a prepared statement through the application's SQL class, with the values bound as parameters.
	 *
	 * The bind type string (one letter per value) is derived from the PHP types: int = `i`, float = `d`,
	 * everything else = `s`. Without values the statement is run without binding.
	 *
	 * Assumes the signature `SQL::select($sql, $types, ...$params)` (one row) and `SQL::select_array(...)` (all
	 * rows) of the application's SQL class; adjust here if it differs.
	 *
	 * @param bool   $allRows true = fetch all rows (`select_array`); false = fetch one row (`select`).
	 * @param string $sql     The statement, with `?` placeholders.
	 * @param array  $params  The values for the placeholders, in order.
	 *
	 * @return mixed What the SQL class returns: a list of rows or a single row, empty/null if there is none.
	 *
	 * @internal Shared with DBStore.
	 */
	public static function run(bool $allRows, string $sql, array $params): mixed {
		$types = implode('', array_map(fn($p) => match (true) {
			is_int($p) => 'i',
			is_float($p) => 'd',
			default => 's',
		}, $params));

		if ($allRows) {
			return $params ? SQL::select_array($sql, $types, ...$params) : SQL::select_array($sql);
		}
		return $params ? SQL::select($sql, $types, ...$params) : SQL::select($sql);
	}
}
