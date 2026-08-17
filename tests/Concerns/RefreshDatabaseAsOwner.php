<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, but `migrate:fresh` runs as the owning role while the per-test transaction
 * still runs on the default connection as the DML-only app role — the split that makes the
 * suite exercise row-level security rather than bypass it.
 *
 * This has to wrap the trait rather than override `migrateFreshUsing()` on TestCase, because
 * RefreshDatabase pulls that method in from CanConfigureMigrationCommands, and a trait method
 * takes precedence over an inherited one.
 */
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [...$this->baseMigrateFreshUsing(), '--database' => 'pgsql_migrator'];
    }
}
