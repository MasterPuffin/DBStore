<?php

/**
 * @property $id
 */
class DBStore {
	//TODO make universal
	private static string $modelPrefix = 'app_models_';
	private static string $namespacePrefix = '\\App\\Models\\';

	public function __construct(?int $id = null) {
		if (!is_null($id)) {
			$this->getById($id);
		}
	}

	public function cast($query): void {
		$this->autocast($query);
	}

	public function getById(int $id): void {
		$this->id = $id;
		$this->get();
	}

	public function add(): int {
		$qryStr = [];
		$paramTypes = [];
		$params = [];
		foreach ($this as $name => $value) {
			$qryStr[] = $name;
			switch (self::_getType($value)) {
				case 'class':
					$params[] = $value->id;
					$paramTypes[] = 'i';
					break;
				case 'enum':
					$params[] = $value->value;
					$paramTypes[] = 's';
					break;
				case 'object':
				case 'array':
					$params[] = serialize($value);
					$paramTypes = 's';
					break;
				default:
					$params[] = $value;
					$paramTypes = self::_varTypeToDbType($value);
					break;
			}
		}
		return SQL::iud(
			'INSERT INTO ' . $this->getDbTableName() . ' (' . implode(', ', $qryStr) . ') VALUES (' . implode(',', array_fill(0, count($params), '?')) . ')',
			implode('', $paramTypes),
			...$params
		);
	}

	/**
	 * @throws Exception
	 */
	public function get(): void {
		$query = SQL::select('SELECT * FROM ' . $this->getDbTableName() . ' WHERE id LIKE ?', 'i', $this->id);
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

	public function edit(): void {
		$qryStr = [];
		$paramTypes = [];
		$params = [];
		foreach ($this as $name => $value) {
			$qryStr[] = $name;
			switch (self::_getType($value)) {
				case 'class':
					$params[] = $value->id;
					$paramTypes[] = 'i';
					break;
				case 'enum':
					$params[] = $value->value;
					$paramTypes[] = 's';
					break;
				case 'object':
				case 'array':
					$params[] = serialize($value);
					$paramTypes = 's';
					break;
				default:
					$params[] = $value;
					$paramTypes = self::_varTypeToDbType($value);
					break;
			}
		}

		SQL::iud('UPDATE ' . $this->getDbTableName() . ' SET ' . implode(' = ?, ', $qryStr) . ' = ? WHERE id LIKE ?',
			implode('', $paramTypes), ...$params);
	}

	public function delete(): void {
		SQL::iud('DELETE FROM ' . $this->getDbTableName() . ' WHERE id = ?', 'i', $this->id);
	}

	protected function autocast(array $query): void {
		$classname = get_class($this);

		$reflectionClass = new ReflectionClass($classname);
		$properties = $reflectionClass->getProperties();

		foreach ($properties as $property) {
			$type = $property->getType();
			if (enum_exists($type)) {
				$enumClass = $type->getName();
				$this->{$property->getName()} = $enumClass::from($query[$property->getName()]);

			} elseif (class_exists($type)) {
				$propertyClassname = $type->getName();
				$this->{$property->getName()} = new $propertyClassname($query[$property->getName()]);
			} else {
				$value = $query[$property->getName()];
				$this->{$property->getName()} = SQL::is_serialized($value) ? unserialize($value) : $value;
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
			case 'int':
			case 'class':
			case 'float':
			case 'double':
			case 'boolean':
				return 'i';
		}
	}

	private function getTableName(): string {
		return self::S_getTableName($this);
	}

	private static function S_getTableName($context): string {
		return strtolower(get_class($context));
	}

	private function getDbTableName(): string {
		return self::S_getDbTableName($this);
	}

	private static function S_getDbTableName($context): string {
		$class = str_replace('\\', '_', str_replace('/', '_', strtolower(is_string($context) ? $context : get_class($context))));
		if (str_starts_with($class, self::$modelPrefix)) {
			$class = substr($class, strlen(self::$modelPrefix));
		}
		return $class;
	}

	private static function _getPropertiesOfClass($class): array {
		$classname = get_class($class);

		$reflectionClass = new ReflectionClass($classname);
		$properties = $reflectionClass->getProperties();
		$propertyTypes = [];

		foreach ($properties as $property) {
			$type = $property->getType();
			if (enum_exists($type)) {
				$finalType = 'enum';
			} elseif (class_exists($type)) {
				$finalType = 'class';
			} else {
				$finalType = $type ? $type->getName() : 'mixed';
			}

			$propertyTypes[$property->name] = $finalType;
		}
		return $propertyTypes;
	}

	public function generateTableStructure(): string {
		$propertyTypes = self::_getPropertiesOfClass($this);
		$columns = [];

		foreach ($propertyTypes as $name => $value) {
			if ($name === 'id')
				continue;

			switch ($value) {
				default:
				case 'enum':
				case 'string':
					$varType = 'VARCHAR(99)';
					break;
				case 'boolean':
					$varType = 'INT(1)';
					break;
				case 'class':
				case 'integer':
					$varType = 'INT(9)';
					break;
				case 'array':
				case 'object':
					$varType = 'TEXT';
					break;
				case 'double':
					$varType = 'DOUBLE';
					break;
			}
			$columns[] = '`' . $name . '` ' . $varType . ' NOT NULL';
		}
		return 'CREATE TABLE IF NOT EXISTS `' . $this->getDbTableName() . '` (`id` INT NOT NULL AUTO_INCREMENT , ' . implode(', ', $columns) . ', PRIMARY KEY (`id`));';
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
		return $statements;
	}

	private static function scanAllDir($dir): array {
		$result = [];
		foreach (scandir($dir) as $filename) {
			if ($filename[0] === '.')
				continue;
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