<?php

class DBStore {
	//TODO make universal
	private static string $modelPrefix = 'app_models_';

	public function add(): int {
		$qryStr = [];
		$paramTypes = [];
		$params = [];
		foreach ($this as $name => $value) {
			$qryStr[] = $name;
			$paramTypes[] = self::varTypeToDbType(gettype($value));
			$params[] = is_object($value) || is_array($value) ? serialize($value) : $value;
		}
		return SQL::iud(
			'INSERT INTO ' . $this->getDbTableName() . ' (' . implode(', ', $qryStr) . ') VALUES (' . implode(',', array_fill(0, count($params), '?')) . ')',
			implode('', $paramTypes),
			...$params
		);
	}

	public function get(): void {
		$query = SQL::select('SELECT * FROM ' . $this->getDbTableName() . ' WHERE id LIKE ?', 'i', $this->id);
		if (is_null($query)) {
			throw new Exception(get_class($this) . " not found", 404);
		}
		$this->autocast($query);
	}

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
			if ($name === 'id') continue;
			$qryStr[] = $name;
			$paramTypes[] = self::varTypeToDbType(gettype($value));
			$params[] = is_object($value) ? serialize($value) : $value;
		}
		$params[] = $this->id;
		$paramTypes[] = 'i';

		SQL::iud('UPDATE ' . $this->getDbTableName() . ' SET ' . implode(' = ?, ', $qryStr) . ' = ? WHERE id LIKE ?',
			implode('', $paramTypes), ...$params);
	}

	public function delete(): void {
		SQL::iud('DELETE FROM ' . $this->getDbTableName() . ' WHERE id = ?', 'i', $this->id);
	}

	protected function autocast(array $query): void {
		foreach ($query as $key => $value) {
			$this->{$key} = is_serialized($value) ? unserialize($value) : $value;
		}
	}

	protected static function autocastArray(array $query) {
		$result = array();
		$obj = static::class;
		foreach ($query as $entry) {
			$tmp = new $obj();
			$tmp->autocast($entry);
			array_push($result, $tmp);
		}
		return $result;
	}

	private static function varTypeToDbType(string $vartype): string {
		switch ($vartype) {
			default:
			case 'string':
			case 'object':
				return 's';
			case 'int':
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
		if (strpos($class, self::$modelPrefix) === 0) {
			$class = substr($class, strlen(self::$modelPrefix));
		}
		return $class;
	}

	public function generateTableStructure(): string {
		$classname = get_class($this);
		$x = new $classname();
		$columns = [];

		foreach (get_class_vars($this->getTableName()) as $name => $value) {
			if ($name === 'id') continue;

			try {
				$x->{$name} = 1;
			} catch (TypeError $e) {
			}
			switch (gettype($x->{$name})) {
				default:
				case 'string':
					$varType = 'VARCHAR(99)';
					break;
				case 'boolean':
					$varType = 'INT(1)';
					break;
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

	public static function generateAllTableStructures(string $dir = __DIR__): void {
		$classes = self::scanAllDir($dir);

		foreach ($classes as $class) {
			if (!str_ends_with($class, '.php')) {
				continue;
			}
			$className = strtok(str_replace('/', '\\', $class), '.');

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

			echo $instance->generateTableStructure() . "<br>";
		}
	}

	private static function scanAllDir($dir) {
		$result = [];
		foreach (scandir($dir) as $filename) {
			if ($filename[0] === '.') continue;
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