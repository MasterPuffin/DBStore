<?php

namespace MasterPuffin\DBStore;

use Attribute;

/**
 * Marks a property that holds another model and stores its id in the column `<property>_id`, or in the property
 * name if it already ends in `_id` or `Id`. Typed with the model class, the related model is loaded lazily on
 * first access (see {@see DBStore::lazy()}).
 *
 * ```php
 * #[ForeignKey(User::class)]
 * public ?User $user = null; // column user_id
 * ```
 */
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
