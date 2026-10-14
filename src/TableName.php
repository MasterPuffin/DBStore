<?php

namespace MasterPuffin\DBStore;

use Attribute;

#[Attribute]
class TableName {
	public function __construct(
		public string $tableName,
	) {
	}
}