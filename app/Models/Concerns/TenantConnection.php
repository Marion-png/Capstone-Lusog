<?php

namespace App\Models\Concerns;

use App\Support\Tenancy;

/**
 * Marks a model as school-owned: its rows live in the institution's private
 * database, bound per request by App\Support\Tenancy.
 *
 * The connection is resolved at call time rather than pinned with
 * `protected $connection` so that shared-database mode (the test suite) can
 * fold the tenant onto the default connection. That matters for more than
 * naming: RefreshDatabase wraps the default connection in an uncommitted
 * transaction, and a second connection pointed at the same database would not
 * be able to see rows written inside it.
 */
trait TenantConnection
{
    public function getConnectionName()
    {
        return Tenancy::connectionName();
    }
}
