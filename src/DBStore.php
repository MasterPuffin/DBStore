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
	private static string $publicIdName = 'nanoId';
	private static string $publicIdGenerateFunction = 'generateNanoId';

	public function __construct(string|int|null $id = null) {
		if (!is_null($id)) {
			$this->getById($id);
		}
	}

	public function cast($query): void {
		$this->autocast($query);
	}

	public function getById(string|int $id): void {
		if (is_string($id)) {
			$this->{self::$publicIdName} = $id;
		} else {
			$this->id = $id;
		}
		$this->get();
	}

	public function add(): int {
		$qryStr = [];
		$paramTypes = [];
		$params = [];
		$reflectionClass = new ReflectionClass($this);

		if (!empty(self::$publicIdGenerateFunction) && $reflectionClass->hasProperty(self::$publicIdName) && !isset($this->{self::$publicIdName})) {
			$this->{self::$publicIdName} = call_user_func(self::$publicIdGenerateFunction);
		}

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

		$id = SQL::iud(
			'INSERT INTO `' . $this->_getDbTableName() . '` (' . implode(', ', array_map(fn($item) => "`$item`", $qryStr)) . ') VALUES (' . implode(',', array_fill(0, count($params), '?')) . ')',
			implode('', $paramTypes),
			...$params
		);
		$this->id = $id;
		return $this->id;
	}


	/**
	 * @throws Exception
	 */
	public function get(): void {
		if (isset($this->id)) {
			$query = SQL::select('SELECT * FROM ' . $this->_getDbTableName() . ' WHERE id LIKE ?', 'i', $this->id);
		} elseif (isset($this->{self::$publicIdName})) {
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
			if ($property->name === 'id') {
				continue;
			}

			$type = $property->getType();
			$foreignKeyAttributes = $property->getAttributes(ForeignKey::class);

			// Handle column definition
			if (!empty($foreignKeyAttributes)) {
				$foreignKey = $foreignKeyAttributes[0]->newInstance();
				$colStr = '`' . $property->name . '_id` INT(9)';

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
						'CONSTRAINT `fk_%s_%s` FOREIGN KEY (`%s_id`) REFERENCES `%s`(`id`) %s %s',
						$this->_getDbTableName(),
						$property->name,
						$property->name,
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