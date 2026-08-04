<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Database Naming
    |--------------------------------------------------------------------------
    |
    | Each institution owns a physically separate PostgreSQL database named
    | <prefix><institution id>. The prefix is configurable so a staging server
    | can host its tenants alongside production ones without colliding.
    |
    */

    'database_prefix' => env('TENANCY_DATABASE_PREFIX', 'capstone_lusog_inst_'),

    /*
    |--------------------------------------------------------------------------
    | Shared Database Mode (testing only)
    |--------------------------------------------------------------------------
    |
    | When true, binding a tenant points the `tenant` connection back at the
    | default database instead of a per-institution one. The test suite runs
    | this way so that RefreshDatabase keeps working on a single database —
    | provisioning and migrating a real database for every test would add
    | minutes to the run.
    |
    | Physical separation is proven separately by InstitutionDatabaseIsolationTest,
    | which turns this off and provisions real tenant databases. Never enable
    | this outside of testing: it collapses every school into one database.
    |
    */

    'shared_database' => (bool) env('TENANCY_SHARED_DATABASE', false),

];
