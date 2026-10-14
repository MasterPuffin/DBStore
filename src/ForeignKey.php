<?php

namespace MasterPuffin\DBStore;

use Attribute;

#[Attribute]
class ForeignKey {
	public function __construct(
		public string     $className,
		public Constraint $onUpdate = Constraint::None,
		public Constraint $onDelete = Constraint::None,
		public ?string    $constraintTableName = null
	) {
	}
}