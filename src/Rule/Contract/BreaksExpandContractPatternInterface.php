<?php

declare(strict_types=1);

namespace Indoctrinate\Rule\Contract;

/**
 * Marker for rules that mutate existing columns or table properties in-place
 * (e.g. MODIFY COLUMN, CONVERT TO CHARACTER SET, ENGINE=) rather than following
 * the Expand/Contract pattern of adding alongside → migrating → removing.
 *
 * These operations may lock tables and are not safe to run against a live database
 * without a maintenance window or prior backup.
 */
interface BreaksExpandContractPatternInterface
{
}
