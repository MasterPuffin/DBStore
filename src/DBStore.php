<?php

namespace MasterPuffin\DBStore;

use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use SQL;

/**
 * @property $id
 */
class DBStore {
	//TODO make universal
	private static string $modelPrefix = 'app_models_';
	private static string $namespacePrefix = '\\App\\Models\\';
	private static string $publicIdName = 'nanoId';
	private static string $publicIdGenerateFunction = 'generateNanoId';

	/**
	 * false (default): add() and edit() write through SQL::iud(), which strips tags and HTML-encodes string
	 * values. true: strings are written exactly as they are (SQL::iud_escaped()), for models whose values are
	 * not output as HTML as stored, or that must keep their exact bytes (e.g. postal addresses).
	 *
	 * @var bool
	 */
	protected static bool $storeRawStrings = false;

	public function __construct(string|int|null $id = null) {
		if (!is_null($id)) {
			$this->getById($id);
		}
	}

	public function cast($query): void {
		$this->autocast($query);
	}

	public function getById(string|int $id): void {
		if (is_string($id) && property_exists($this, self::$publicIdName)) {
			$this->{self::$publicIdName} = $id;
		} else {
			$this->id = $id;
		}
		$this->get();
	}

	public function add(): int {
		$reflectionClass = new ReflectionClass($this);
		if (!empty(self::$publicIdGenerateFunction) && $reflectionClass->hasProperty(self::$publicIdName) && !isset($this->{self::$publicIdName})) {
			$this->{self::$publicIdName} = call_user_func(self::$publicIdGenerateFunction);
		}

		[$columns, $types, $params] = $this->toColumns($this->persistedValues());
		$this->id = self::write(
			'INSERT INTO `' . $this->_getDbTableName() . '` (' . implode(', ', array_map(fn($column) => "`$column`", $columns)) . ') VALUES (' . implode(',', array_fill(0, count($params), '?')) . ')',
			$types,
			...$params
		);
		return $this->id;
	}


	/**
	 * @throws Exception
	 */
	public function get(): void {
		if (isset($this->id)) {
			$query = SQL::select('SELECT * FROM ' . $this->_getDbTableName() . ' WHERE id LIKE ?', 'i', $this->id);
		} elseif (property_exists($this, self::$publicIdName) && isset($this->{self::$publicIdName})) {
			$query = SQL::select('SELECT * FROM ' . $this->_getDbTableName() . ' WHERE ' . self::$publicIdName . ' = ?', 's', $this->{self::$publicIdName});
		} else {
			throw new Exception("No ID set", 404);
		}

		if (is_null($query)) {
			throw new Exception(get_class($this) . " not found", 404);
		}
		$this->autocast($query);
	}

	/**
	 * @throws Exception
	 */
	public static function getAll(): array {
		$query = SQL::select_array('SELECT * FROM ' . self::S_getDbTableName(get_called_class()));
		if (is_null($query)) {
			throw new Exception(get_called_class() . " not found", 404);
		}
		return self::autocastArray($query);
	}

	// ---------------------------------------------------------------------
	// Filtered, sorted and paged lists.
	//
	// This section lets the database do the filtering, sorting and paging, so that a list view does not have
	// to load a whole table into PHP (getAll()) and cut it down afterwards. It is used by the Datatable
	// component for AJAX tables, but is a general API of every model:
	//
	//     $where = Condition::all(Condition::where('active', '=', true),
	//                             Condition::where('name', 'contains', 'adm'));
	//     $total = Group::countWhere($where);
	//     $page  = Group::findPaged($where, [['name', 'asc']], 25, 50);   // rows 51 to 75
	//
	// Filters are Condition objects and sort columns are property names: no SQL text is accepted at all,
	// property names must be real model properties, and all values (even LIMIT/OFFSET) are bound as
	// parameters. That is what makes it safe to feed these methods with data derived from user input.
	//
	// All methods are static and operate on the model they are called on (Group::findPaged() queries the
	// groups table). The helpers below are private; `self::` calls keep the called class (late static
	// binding), which is why they can use `static::class` to find out which model is meant.
	// ---------------------------------------------------------------------

	/**
	 * Upper limit for findPaged(), whatever the caller asks for. A last line of defence against code that
	 * passes an unchecked page size; the Datatable component caps the size much lower itself.
	 *
	 * @var int
	 */
	private const MAX_PAGE_SIZE = 1000;

	/**
	 * Counts the rows of this model's table that match a condition, using `SELECT COUNT(*)`, so no rows are
	 * transferred.
	 *
	 * @param Condition|null $where The filter. Null counts all rows.
	 *
	 * @return int The number of matching rows.
	 *
	 * @throws InvalidArgumentException The condition names a property that is not a property of this model.
	 */
	public static function countWhere(?Condition $where = null): int {
		[$whereSql, $params] = self::compileWhere($where);
		$row = self::run(false, 'SELECT COUNT(*) AS cnt FROM ' . self::quotedTable() . $whereSql, $params);
		return is_array($row) ? (int)($row['cnt'] ?? 0) : 0;
	}

	/**
	 * Loads one page of rows: filtered, sorted and cut to a window, all in one SQL query
	 * (`SELECT * ... WHERE ... ORDER BY ... LIMIT ? OFFSET ?`).
	 *
	 * Sorting by a column whose values are not unique does not give a stable order across pages by itself. Add
	 * a unique column such as `['id', 'asc']` as the last sort criterion, otherwise rows can repeat or go
	 * missing when paging. The Datatable component does this.
	 *
	 * @param Condition|null                          $where   The filter. Null = no filter.
	 * @param array<int, array{0: string, 1: string}> $orderBy Sort criteria, most important first, each as
	 *                                                         `[property, 'asc'|'desc']`. The property is a model
	 *                                                         property name (not a column name). Empty = database
	 *                                                         default order, which is not guaranteed to be stable.
	 * @param int                                     $limit   Rows per page, 1 to {@see DBStore::MAX_PAGE_SIZE}.
	 * @param int                                     $offset  Number of rows to skip, 0 or more.
	 *
	 * @return static[] The model objects of the page (empty array if there are none), created like those of
	 *                  getAll(), so values have their PHP types.
	 *
	 * @throws InvalidArgumentException `$limit` or `$offset` out of range, a sort direction other than
	 *                                  asc/desc, or a property that does not exist on this model.
	 */
	public static function findPaged(?Condition $where, array $orderBy, int $limit, int $offset = 0): array {
		if ($limit < 1 || $limit > self::MAX_PAGE_SIZE || $offset < 0) {
			throw new InvalidArgumentException('Invalid limit or offset');
		}
		[$whereSql, $params] = self::compileWhere($where);

		$order = [];
		foreach ($orderBy as [$property, $direction]) {
			$order[] = self::column($property) . match ($direction) {
					'asc' => ' ASC',
					'desc' => ' DESC',
					default => throw new InvalidArgumentException('Invalid sort direction'),
				};
		}

		$sql = 'SELECT * FROM ' . self::quotedTable() . $whereSql
			. ($order ? ' ORDER BY ' . implode(', ', $order) : '')
			. ' LIMIT ? OFFSET ?';
		$rows = self::run(true, $sql, [...$params, $limit, $offset]);
		return empty($rows) ? [] : self::autocastArray($rows);
	}

	/**
	 * Compiles an optional condition into a WHERE clause for this model.
	 *
	 * @param Condition|null $where The filter, or null for none.
	 *
	 * @return array{0: string, 1: array} The SQL fragment including the leading ` WHERE ` (empty string without a
	 *                                    condition), and the values to bind in placeholder order.
	 */
	private static function compileWhere(?Condition $where): array {
		if ($where === null) {
			return ['', []];
		}
		[$sql, $params] = $where->compile(self::column(...));
		return [' WHERE ' . $sql, $params];
	}

	/**
	 * Turns a property name into a safely quoted column name. This is the single gate through which every
	 * name that is used in a query passes, which makes it the core of the injection protection of this section.
	 *
	 * The name is accepted only if it is `id` or a real, non-static property of the model that was called
	 * (found by reflection; `self::` calls forward the called class, so `static::class` is that model).
	 * Anything else, including every string that merely looks like SQL, is rejected with an exception.
	 *
	 * Properties with the {@see ForeignKey} attribute are stored in a column named `<property>_id`, the same
	 * rule that add() and edit() apply, unless the name already ends in `_id` or `Id`.
	 *
	 * @param string $property Name of a model property.
	 *
	 * @return string The column name in backticks, e.g. `` `name` ``.
	 *
	 * @throws InvalidArgumentException The property does not exist on the model, or is static or {@see Transient}.
	 */
	private static function column(string $property): string {
		if ($property !== 'id') {
			$model = new ReflectionClass(static::class);
			if (!$model->hasProperty($property) || !self::isPersisted($model->getProperty($property))) {
				throw new InvalidArgumentException('Unknown property ' . static::class . '::$' . $property);
			}
			// foreign keys are stored as <name>_id, same rule as add() and edit()
			if ($model->getProperty($property)->getAttributes(ForeignKey::class) && !preg_match('/(_id|Id)$/', $property)) {
				$property .= '_id';
			}
		}
		return self::quote($property);
	}

	/**
	 * The quoted table name of the model that was called. It comes from the model's TableName attribute or
	 * class name (see S_getDbTableName()), i.e. from the code, never from a request.
	 *
	 * @return string The table name in backticks.
	 */
	private static function quotedTable(): string {
		return self::quote(self::S_getDbTableName(static::class));
	}

	/**
	 * Quotes an identifier (table or column name) with backticks and doubles any backtick inside it, so the
	 * name cannot end the quoting early. Defence in depth: names are already whitelisted before they get here.
	 *
	 * @param string $identifier The name to quote.
	 *
	 * @return string The quoted name.
	 */
	private static function quote(string $identifier): string {
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
	 */
	private static function run(bool $allRows, string $sql, array $params): mixed {
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

	/**
	 * Writes all persisted properties (see {@see DBStore::persistedValues()}) to the row of this object.
	 */
	public function edit(): void {
		$values = $this->persistedValues();
		unset($values['id']);
		$this->update($values);
	}

	/**
	 * Writes only the given properties to the row of this object, e.g. `$user->editColumns('password')`. Use it
	 * when the other properties of the object may be outdated and must not overwrite newer values.
	 *
	 * @param string ...$properties Names of persisted, initialized properties.
	 *
	 * @throws InvalidArgumentException A name is not a persisted property, or the property is not initialized.
	 */
	public function editColumns(string ...$properties): void {
		$all = $this->persistedValues();
		$values = [];
		foreach ($properties as $property) {
			if ($property === 'id' || !array_key_exists($property, $all)) {
				throw new InvalidArgumentException('Cannot edit ' . static::class . '::$' . $property);
			}
			$values[$property] = $all[$property];
		}
		$this->update($values);
	}

	/**
	 * @param array<string, mixed> $values Property name => value, without id.
	 */
	private function update(array $values): void {
		if ($values === []) {
			return;
		}
		[$columns, $types, $params] = $this->toColumns($values);
		self::write('UPDATE `' . $this->_getDbTableName() . '` SET ' . implode(', ', array_map(fn($column) => "`$column` = ?", $columns)) . ' WHERE id LIKE ?',
			$types . 'i', ...[...$params, $this->id]);
	}

	/**
	 * Converts property values into what is written to the database: foreign keys become `<name>_id` with the id
	 * of the referenced object, enums their value, other objects and arrays are serialized.
	 *
	 * @param array<string, mixed> $values Property name => value.
	 *
	 * @return array{0: string[], 1: string, 2: array} Column names, bind types, bind values.
	 */
	private function toColumns(array $values): array {
		$reflectionClass = new ReflectionClass($this);
		$columns = [];
		$types = '';
		$params = [];

		foreach ($values as $name => $value) {
			if (!empty($reflectionClass->getProperty($name)->getAttributes(ForeignKey::class))) {
				// Foreign key: column <name>_id unless the name already ends with Id or _id
				$columns[] = preg_match('/(_id|Id)$/', $name) ? $name : $name . '_id';
				$params[] = is_object($value) ? ($value->id ?? null) : $value;
				$types .= 'i';
				continue;
			}

			$columns[] = $name;
			switch (self::_getType($value)) {
				case 'class':
					$params[] = $value->id;
					$types .= 'i';
					break;
				case 'enum':
					$params[] = $value->value;
					$types .= 's';
					break;
				case 'object':
				case 'array':
					$params[] = serialize($value);
					$types .= 's';
					break;
				default:
					$params[] = $value;
					$types .= self::_varTypeToDbType($value);
					break;
			}
		}
		return [$columns, $types, $params];
	}

	/**
	 * Runs an INSERT/UPDATE of add() or edit(), escaping strings unless the model sets
	 * {@see DBStore::$storeRawStrings}.
	 */
	private static function write(string $sql, string $types, mixed ...$params): int {
		return static::$storeRawStrings ? SQL::iud_escaped($sql, $types, ...$params) : SQL::iud($sql, $types, ...$params);
	}

	/**
	 * The values that add() and edit() write: every initialized, non-static, non-private property that is not
	 * marked {@see Transient}, in declaration order. Private properties are skipped because they were never
	 * visible to the former `foreach ($this ...)` either. Transient properties are skipped before they are
	 * read, so property hooks on them (e.g. lazy loading) are not triggered by saving.
	 *
	 * @return array<string, mixed> Property name => value.
	 */
	private function persistedValues(): array {
		$values = [];
		foreach ((new ReflectionClass($this))->getProperties() as $property) {
			if ($property->isPrivate() || !self::isPersisted($property) || !$property->isInitialized($this)) {
				continue;
			}
			$values[$property->getName()] = $property->getValue($this);
		}
		return $values;
	}

	/**
	 * Whether a property is a database column: not static and not marked {@see Transient}.
	 *
	 * @param \ReflectionProperty $property
	 *
	 * @return bool
	 */
	private static function isPersisted(\ReflectionProperty $property): bool {
		return !$property->isStatic() && empty($property->getAttributes(Transient::class));
	}

	public function delete(): void {
		SQL::iud('DELETE FROM `' . $this->_getDbTableName() . '` WHERE id = ?', 'i', $this->id);
	}

	/**
	 * @throws ReflectionException
	 */
	protected function autocast(array $query): void {
		$classname = get_class($this);
		$reflectionClass = new ReflectionClass($classname);
		$properties = $reflectionClass->getProperties();

		foreach ($properties as $property) {
			if (!self::isPersisted($property)) {
				continue;
			}
			$type = $property->getType();
			$attributes = $property->getAttributes(ForeignKey::class);
			$propertyName = $property->getName();

			if (!empty($attributes)) {
				// Handle foreign key column resolution
				$columnName = preg_match('/(_id|Id)$/', $propertyName) ? $propertyName : $propertyName . '_id';
				$foreignKeyId = $query[$columnName] ?? null;

				if (!is_null($foreignKeyId)) {
					// Check if the property expects a class instance or a built-in type (like int)
					$isBuiltin = true;
					if ($type !== null) {
						$types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
						foreach ($types as $t) {
							if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
								$isBuiltin = false;
								break;
							}
						}
					}

					if (!$isBuiltin) {
						$foreignClass = $attributes[0]->getArguments()[0];
						$this->$propertyName = new $foreignClass($foreignKeyId);
					} else {
						$this->$propertyName = $foreignKeyId;
					}
				} else {
					$this->$propertyName = null;
				}
			} else {
				$value = $query[$propertyName] ?? null;

				// Extract the class name safely, handling both single and union types (e.g., ?MyClass)
				$propertyClassname = null;
				if ($type !== null) {
					$types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
					foreach ($types as $t) {
						if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
							$propertyClassname = $t->getName();
							break;
						}
					}
				}

				// Instantiate the class or enum if it exists
				if ($propertyClassname !== null && (class_exists($propertyClassname) || enum_exists($propertyClassname)) && !is_null($value)) {
					if ((new ReflectionClass($propertyClassname))->isEnum()) {
						$this->$propertyName = $propertyClassname::from($value);
					} else {
						$this->$propertyName = new $propertyClassname($value);
					}
				} else {
					// Fallback to primitive value or deserialization
					$this->$propertyName = SQL::is_serialized($value) ? unserialize($value) : $value;
				}
			}
		}
	}


	protected static function autocastArray(array $query): array {
		$result = [];
		$obj = static::class;
		foreach ($query as $entry) {
			$tmp = new $obj();
			$tmp->autocast($entry);
			$result[] = $tmp;
		}
		return $result;
	}


	private static function _getType($value): string {
		if (is_object($value)) {
			$reflectionClass = new ReflectionClass($value);
			if ($reflectionClass->isEnum()) {
				return 'enum';
			}
			return 'class';
		}
		return gettype($value);
	}

	private static function _varTypeToDbType($value): string {
		switch (self::_getType($value)) {
			default:
			case 'enum':
			case 'string':
			case 'object':
				return 's';
			case 'float':
			case 'double': // gettype() of a float
				return 'd';
			case 'int':
			case 'integer': // gettype() of an int
			case 'class':
			case 'boolean':
				return 'i';
		}
	}

	private function _getDbTableName(): string {
		return self::S_getDbTableName($this);
	}

	protected static function getDbTableName(): string {
		return self::S_getDbTableName(get_called_class());
	}

	private static function S_getDbTableName($context): string {
		$reflection = new ReflectionClass(is_string($context) ? $context : get_class($context));
		$attributes = $reflection->getAttributes(TableName::class);
		if (!empty($attributes)) {
			//Check if another class sets the table name
			if (class_exists($attributes[0]->getArguments()[0])) {
				return self::S_getDbTableName($attributes[0]->getArguments()[0]);
			} else {
				return $attributes[0]->getArguments()[0];
			}
		}

		$class = str_replace(array('/', '\\'), '_', strtolower(is_string($context) ? $context : get_class($context)));
		if (str_starts_with($class, self::$modelPrefix)) {
			$class = substr($class, strlen(self::$modelPrefix));
		}

		return $class;
	}

	/**
	 * @throws ReflectionException
	 */
	public function generateTableStructure(): string {
		$classname = get_class($this);
		$reflectionClass = new ReflectionClass($classname);
		$properties = $reflectionClass->getProperties();
		$columns = [];
		$constraints = [];

		foreach ($properties as $property) {
			if ($property->name === 'id' || !self::isPersisted($property)) {
				continue;
			}

			$type = $property->getType();
			$foreignKeyAttributes = $property->getAttributes(ForeignKey::class);

			// Handle column definition
			if (!empty($foreignKeyAttributes)) {
				$foreignKey = $foreignKeyAttributes[0]->newInstance();

				// Detect column name mapping dynamically
				$columnName = preg_match('/(_id|Id)$/', $property->name) ? $property->name : $property->name . '_id';
				$colStr = '`' . $columnName . '` INT(9)';

				$onDelete = null;
				$onUpdate = null;
				if ($foreignKey->onDelete !== Constraint::None) {
					$onDelete = 'ON DELETE ' . match ($foreignKey->onDelete) {
							Constraint::Cascade => 'CASCADE',
							Constraint::SetNull => 'SET NULL',
							Constraint::NoAction => 'NO ACTION',
							Constraint::Restrict => 'RESTRICT',
						};
				}
				if ($foreignKey->onUpdate !== Constraint::None) {
					$onUpdate = 'ON UPDATE ' . match ($foreignKey->onUpdate) {
							Constraint::Cascade => 'CASCADE',
							Constraint::SetNull => 'SET NULL',
							Constraint::NoAction => 'NO ACTION',
							Constraint::Restrict => 'RESTRICT',
						};
				}
				if (!is_null($onUpdate) || !is_null($onDelete)) {
					$constraints[] = sprintf(
						'CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s`) REFERENCES `%s`(`id`) %s %s',
						$this->_getDbTableName(),
						$columnName, // Using resolved column name for FK label
						$columnName,
						!is_null($foreignKey->constraintTableName) ? $foreignKey->constraintTableName : self::S_getDbTableName($foreignKey->className),
						$onDelete,
						$onUpdate
					);
				}
			} else {
				$varTypeAttribute = $property->getAttributes(Type::class);
				if (!empty($varTypeAttribute)) {
					//Check if has length
					if (!isset($varTypeAttribute[0]->getArguments()[1]) || is_null($varTypeAttribute[0]->getArguments()[1])) {
						$varType = $varTypeAttribute[0]->getArguments()[0]->value;
					} else {
						$varType = sprintf('%s(%d)', $varTypeAttribute[0]->getArguments()[0]->value, $varTypeAttribute[0]->getArguments()[1]);
					}
				} else {
					// Original type handling
					if (enum_exists($type)) {
						$finalType = 'enum';
					} elseif (class_exists($type)) {
						$finalType = 'class';
					} else {
						$finalType = $type ? $type->getName() : 'mixed';
					}

					switch ($finalType) {
						default:
						case 'enum':
						case 'string':
							$varType = 'VARCHAR(255)';
							break;
						case 'bool':
						case 'boolean':
							$varType = 'TINYINT(1)';
							break;
						case 'class':
						case 'integer':
						case 'int':
							$varType = 'INT(9)';
							break;
						case 'array':
						case 'object':
							$varType = 'TEXT';
							break;
						case 'double':
						case 'float':
							$varType = 'DOUBLE';
							break;
					}
				}
				$colStr = '`' . $property->name . '` ' . $varType;
			}

			// Handle nullability
			if ($type && !$type->allowsNull()) {
				$colStr .= ' NOT NULL';
			}

			$columns[] = $colStr;
		}

		// Combine columns and constraints
		$tableElements = array_merge($columns, $constraints);

		return sprintf(
			'CREATE TABLE IF NOT EXISTS `%s` (`id` INT NOT NULL AUTO_INCREMENT, %s, PRIMARY KEY (`id`));',
			$this->_getDbTableName(),
			implode(', ', $tableElements)
		);
	}

	/**
	 * @throws ReflectionException
	 */
	public static function generateAllTableStructures(string $dir = __DIR__): array {
		$classes = self::scanAllDir($dir);
		$statements = [];

		foreach ($classes as $class) {
			if (!str_ends_with($class, '.php')) {
				continue;
			}
			$className = strtok(str_replace('/', '\\', $class), '.');
			if (!str_starts_with($className, self::$namespacePrefix)) {
				$className = self::$namespacePrefix . $className;
			}

			if (!is_subclass_of($className, self::class)) {
				continue;
			}

			$reflection = new ReflectionClass($className);
			$paramsToFill = [];
			$constructor = $reflection->getConstructor();
			if (!is_null($constructor)) {
				$params = $constructor->getParameters();
				foreach ($params as $param) {
					switch ($param->getType()) {
						default:
							$paramsToFill[] = null;
							break;
						case 'string':
							$paramsToFill[] = '';
							break;
						case 'int':
							$paramsToFill[] = 0;
							break;
					}
				}
			}
			$instance = new $className(...$paramsToFill);

			$statements[] = $instance->generateTableStructure();
		}
		return array_unique($statements);
	}

	private static function scanAllDir($dir): array {
		$result = [];
		foreach (scandir($dir) as $filename) {
			if ($filename[0] === '.') {
				continue;
			}
			$filePath = $dir . '/' . $filename;
			if (is_dir($filePath)) {
				foreach (self::scanAllDir($filePath) as $childFilename) {
					$result[] = $filename . '/' . $childFilename;
				}
			} else {
				$result[] = $filename;
			}
		}
		return $result;
	}
}