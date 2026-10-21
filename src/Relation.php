<?php

namespace MasterPuffin\DBStore;

use Attribute;

/**
 * Marks a property that holds the id of another model, without loading that model, so that queries can join it
 * as `property.field` (see {@see DBStore::findRows()}). The column is the property name. Unlike
 * {@see ForeignKey} it creates no constraint and does not change the column name.
 *
 * ```php
 * #[Relation(User::class)]
 * public int $user;
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Relation {
	/**
	 * @param class-string $className The related model: a DBStore model, or a class with a {@see TableName}.
	 */
	public function __construct(public string $className) {
	}
}
