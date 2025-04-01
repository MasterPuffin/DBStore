<?php

namespace MasterPuffin\DBStore;

use Exception;
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
		$reflectionClass = new ReflectionClass($this);

		foreach ($this as $name => $value) {
			$property = $reflectionClass->getProperty($name);
			$attributes = $property->getAttributes(ForeignKey::class);

			if (!empty($attributes)) {
				// Handle foreign key
				$qryStr[] = $name . '_id';
				$params[] = $value?->id;
				$paramTypes[] = 'i';
			} else {
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
						$paramTypes[] = 's';
						break;
					default:
						$params[] = $value;
						$paramTypes[] = self::_varTypeToDbType($value);
						break;
				}
			}
		}

		return SQL::iud(
			'INSERT INTO `' . $this->_getDbTableName() . '` (' . implode(', ', array_map(fn($item) => "`$item`", $qryStr)) . ') VALUES (' . implode(',', array_fill(0, count($params), '?')) . ')',
			implode('', $paramTypes),
			...$params
		);
	}


	/**
	 * @throws Exception
	 */
	public function get(): void {
		$query = SQL::select('SELECT * FROM ' . $this->_getDbTableName() . ' WHERE id LIKE ?', 'i', $this->id);
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
		$reflectionClass = new ReflectionClass($this);

		foreach ($this as $name => $value) {
			if ($name === 'id') continue;

			$property = $reflectionClass->getProperty($name);
			$attributes = $property->getAttributes(ForeignKey::class);

			if (!empty($attributes)) {
				// Handle foreign key
				$qryStr[] = $name . '_id';
				$params[] = $value?->id;
				$paramTypes[] = 'i';
			} else {
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
						$paramTypes[] = 's';
						break;
					default:
						$params[] = $value;
						$paramTypes[] = self::_varTypeToDbType($value);
						break;
				}
			}
		}

		$paramTypes[] = 'i';
		$params[] = $this->id;

		SQL::iud('UPDATE `' . $this->_getDbTableName() . '` SET ' . implode(', ', array_map(fn($col) => "`$col` = ?", $qryStr)) . ' WHERE id LIKE ?',
			implode('', $paramTypes), ...$params);
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
			$type = $property->getType();
			$attributes = $property->getAttributes(ForeignKey::class);
			$propertyName = $property->getName();

			if (!empty($attributes)) {
				// Handle foreign key
				$foreignKeyId = $query[$propertyName . '_id'];
				if (!is_null($foreignKeyId)) {
					$foreignClass = $attributes[0]->getArguments()[0];
					$this->$propertyName = new $foreignClass($foreignKeyId);
				} else {
					$this->$propertyName = null;
				}
			} else {
				$value = $query[$propertyName];
				if (class_exists($type->getName()) && !is_null($value)) {
					if ((new ReflectionClass($type->getName()))->isEnum()) {
						$enumClass = $type->getName();
						$this->$propertyName = $enumClass::from($value);
					} else {
						$propertyClassname = $type->getName();
						$this->$propertyName = new $propertyClassname($value);
					}
				} else {
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
			case 'int':
			case 'class':
			case 'float':
			case 'double':
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

		foreach ($properties as $property) {
			if ($property->name === 'id')
				continue;

			$type = $property->getType();
			$attributes = $property->getAttributes(ForeignKey::class);

			// Check if property has ForeignKey attribute
			if (!empty($attributes)) {
				$colStr = '`' . $property->name . '_id` INT(9)';
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
						$varType = 'VARCHAR(99)';
						break;
					case 'bool':
					case 'boolean':
						$varType = 'INT(1)';
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
				$colStr = '`' . $property->name . '` ' . $varType;
			}

			if ($type && !$type->allowsNull()) {
				$colStr .= ' NOT NULL';
			}
			$columns[] = $colStr;
		}

		return 'CREATE TABLE IF NOT EXISTS `' . $this->_getDbTableName() . '` (`id` INT NOT NULL AUTO_INCREMENT , ' . implode(', ', $columns) . ', PRIMARY KEY (`id`));';
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