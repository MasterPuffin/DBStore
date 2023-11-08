<?php

class DBStore {
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
			'INSERT INTO ' . $this->getTableName() . ' (' . implode(', ', $qryStr) . ') VALUES (' . implode(',', array_fill(0, count($params), '?')) . ')',
			implode('', $paramTypes),
			...$params
		);
	}

	public function get(): void {
		$query = SQL::select('SELECT * FROM ' . $this->getTableName() . ' WHERE id LIKE ?', 'i', $this->id);
		if (is_null($query)) {
			throw new Exception(get_class($this) . " not found", 404);
		}
		$this->autocast($query);
	}

	public static function getAll(): array {
		$query = SQL::select_array('SELECT * FROM ' . strtolower(get_called_class()));
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

		SQL::iud('UPDATE ' . $this->getTableName() . ' SET ' . implode(' = ?, ', $qryStr) . ' = ? WHERE id LIKE ?',
			implode('', $paramTypes), ...$params);
	}

	public function delete(): void {
		SQL::iud('DELETE FROM ' . $this->getTableName() . ' WHERE id = ?', 'i', $this->id);
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
		return strtolower(get_class($this));
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
		return 'CREATE TABLE IF NOT EXISTS `' . $this->getTableName() . '` (`id` INT NOT NULL AUTO_INCREMENT , ' . implode(', ', $columns) . ', PRIMARY KEY (`id`));';
	}

	public static function generateAllTableStructures(): void {
		$classes = scandir(__DIR__);
		foreach ($classes as $class) {
			if (!str_ends_with($class, '.php')) {
				continue;
			}
			$className = strtok($class, '.');
			$reflection = new ReflectionClass($className);
			$paramsToFill = [];
			$constructor = $reflection->getConstructor();
			if (!is_null($constructor)) {
				$params = $constructor->getParameters();
				foreach ($params as $param) {
					switch ($param->getType()) {
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
			if (!is_subclass_of($instance, self::class)) {
				continue;
			}
			echo $instance->generateTableStructure() . "<br>";
		}
	}
}