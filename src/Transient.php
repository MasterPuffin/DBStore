<?php

namespace MasterPuffin\DBStore;

use Attribute;

/**
 * Marks a property that is not stored in the database: runtime caches, lazily loaded relations and similar.
 *
 * add() and edit() do not write it, autocast() does not fill it, generateTableStructure() creates no column for
 * it, and it cannot be used as a column in countWhere()/findPaged() conditions or sorting.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Transient {
}
