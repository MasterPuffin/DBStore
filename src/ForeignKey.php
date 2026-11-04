<?php

namespace MasterPuffin\DBStore;

use Attribute;

/**
 * Marks a property that holds another model and stores its id. The column is `<property>_id`, or the property name
 * if it already ends in `_id` or `Id`, unless `$column` names it. Typed with the model class, the related model is
 * loaded lazily on first access (see {@see DBStore::lazy()}).
 *
 * ```php
 * #[ForeignKey(User::class, column: 'userId')]
 * public ?User $user = null;
 * ```
 */
#[Attribute]
class ForeignKey {
	/**
	 * @param string|null $column Name of the id column, null = derived from the property name.
	 */
	public function __construct(
		public string     $className,
		public Constraint $onUpdate = Constraint::None,
		public Constraint $onDelete = Constraint::None,
		public ?string    $constraintTableName = null,
		public ?string    $column = null
	) {
	}
}
