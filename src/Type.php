<?php

namespace MasterPuffin\DBStore;

use Attribute;

#[Attribute]
class Type {
	public function __construct(
		public VarType $type,
		public ?int    $length = null,
	) {
	}
}