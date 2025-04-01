<?php

namespace MasterPuffin\DBStore;

use Attribute;

#[Attribute]
class ForeignKey {
	public function __construct(public string $className) {
	}
}