<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only aggregate inventory for an isolated MSKBA production/dev data merge.
 *
 * Never exports row values, credentials, identities, or personal information.
 * A report is an inventory, not a claim that two databases can be merged safely.
 */
final class AuditDataMergeCommand extends Command
{
    protected $signature = 'mskba:data-merge:audit
        {--connection= : Configured Laravel database connection}
        {--label= : Explicit source label, e.g. prod or dev}
        {--json : Print only JSON}
        {--output= : Create a new report file (refuses to overwrite)}';

    protected $description = 'Read-only PostgreSQL schema, migration and aggregate row-count inventory for data unification';

    private const COUNT_TABLES = [
        'users', 'profiles', 'player_profiles', 'user_participation_roles',
        'contacts', 'contact_verifications', 'telegram_accounts', 'vk_accounts',
        'actors', 'contracts', 'contract_memberships', 'contract_relations',
        'contract_permissions', 'teams', 'team_invitations', 'team_join_requests',
        'events', 'event_participants', 'games', 'game_sides',
        'game_roster_entries', 'game_player_statistics', 'game_actions',
        'game_periods', 'tournaments', 'tournament_admissions',
        'tournament_entries', 'tournament_entry_members', 'tournament_matches',
        'venues', 'venue_courts', 'venue_bookings',
        'venue_ownerships', 'venue_ownership_claims',
        'media', 'site_media', 'page_builder_pages', 'page_builder_components',
        'content_items', 'page_seo_settings', 'user_notifications',
        'user_duplicate_evidence', 'user_duplicates',
        'wallets', 'wallet_transactions', 'ledger_entries',
    ];

    private const STATUS_TABLES = [
        'users', 'teams', 'events', 'games', 'tournaments', 'venues',
    ];

    public function handle(): int
    {
        $label = trim((string) $this->option('label'));
        if ($label === '' || preg_match('/^[a-z0-9_-]{1,40}$/i', $label) !== 1) {
            $this->error('Specify --label=prod or --label=dev (letters, digits, underscore or dash).');

            return self::FAILURE;
        }

        try {
            $db = DB::connection($this->option('connection') ?: null);
            if ($db->getDriverName() !== 'pgsql') {
                $this->error('Only PostgreSQL is supported. No database changes were made.');

                return self::FAILURE;
            }

            $db->beginTransaction();
            try {
                // All subsequent queries operate inside a consistent, server-enforced
                // read-only transaction. No migration or user row can be changed.
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $schema = (string) $db->selectOne('SELECT current_schema() AS name')->name;
                $tableRows = $db->select(
                    'SELECT tablename AS name FROM pg_catalog.pg_tables WHERE schemaname = ? ORDER BY tablename',
                    [$schema],
                );
                $tableNames = array_map(static fn (object $row): string => (string) $row->name, $tableRows);
                $tableSet = array_fill_keys($tableNames, true);
                $counts = [];

                foreach (self::COUNT_TABLES as $table) {
                    if (! isset($tableSet[$table])) {
                        continue;
                    }

                    $identifier = $this->identifier($schema).'.'.$this->identifier($table);
                    $counts[$table] = (int) $db->selectOne(
                        'SELECT COUNT(*) AS total FROM '.$identifier,
                    )->total;
                }

                $columns = $db->select(
                    'SELECT table_name, column_name, data_type, udt_name, is_nullable '
                    .'FROM information_schema.columns WHERE table_schema = ? '
                    .'ORDER BY table_name, ordinal_position',
                    [$schema],
                );

                $foreignKeys = $db->select(
                    'SELECT c.conname, t.relname AS table_name, '
                    .'pg_catalog.pg_get_constraintdef(c.oid) AS definition '
                    .'FROM pg_catalog.pg_constraint c '
                    .'JOIN pg_catalog.pg_class t ON t.oid = c.conrelid '
                    .'JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace '
                    ."WHERE c.contype = 'f' AND n.nspname = ? "
                    .'ORDER BY t.relname, c.conname',
                    [$schema],
                );

                $migrations = isset($tableSet['migrations'])
                    ? $db->table('migrations')->orderBy('migration')->pluck('migration')->all()
                    : [];

                $statusCounts = [];
                foreach (self::STATUS_TABLES as $table) {
                    if (! isset($tableSet[$table])) {
                        continue;
                    }
                    $hasStatus = false;
                    foreach ($columns as $column) {
                        if ($column->table_name === $table && $column->column_name === 'status') {
                            $hasStatus = true;
                            break;
                        }
                    }
                    if (! $hasStatus) {
                        continue;
                    }

                    $rows = $db->table($table)->select('status')
                        ->selectRaw('COUNT(*) AS total')->groupBy('status')->get();
                    $groups = [];
                    foreach ($rows as $row) {
                        $groups[(string) ($row->status ?? '(null)')] = (int) $row->total;
                    }
                    ksort($groups);
                    $statusCounts[$table] = $groups;
                }

                $report = [
                    'report_version' => 1,
                    'source' => $label,
                    'generated_at_utc' => gmdate('c'),
                    'db_engine' => 'pgsql',
                    'schema' => $schema,
                    'tables' => $tableNames,
                    'table_count' => count($tableNames),
                    'row_counts' => $counts,
                    'status_counts' => $statusCounts,
                    'migrations' => $migrations,
                    'migration_count' => count($migrations),
                    'columns_digest_sha256' => $this->digest($columns),
                    'foreign_keys_count' => count($foreignKeys),
                    'foreign_keys_digest_sha256' => $this->digest($foreignKeys),
                    'limitations' => [
                        'Exact row counts cover only the enumerated business tables.',
                        'No user-level duplicate matching or cross-source ID mapping is performed.',
                        'Polymorphic relations and referenced media files require separate validation.',
                        'This report does not replace a restorable database and media backup.',
                    ],
                ];
            } finally {
                $db->rollBack();
            }

            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $path = trim((string) $this->option('output'));
            if ($path !== '') {
                // Exclusive creation: an old inventory cannot be overwritten.
                $oldUmask = umask(0077);
                try {
                    $file = @fopen($path, 'x');
                } finally {
                    umask($oldUmask);
                }
                if ($file === false) {
                    $this->error('Could not create report file (or file already exists): '.$path);

                    return self::FAILURE;
                }
                try {
                    fwrite($file, $json."\n");
                } finally {
                    fclose($file);
                }
            }

            if ($this->option('json')) {
                $this->line($json);
            } else {
                $this->info('Read-only snapshot '.$label.': '.count($tableNames).' tables, '.count($migrations).' migrations.');
                foreach ($counts as $name => $count) {
                    $this->line(str_pad($name, 35).$count);
                }
                if ($path !== '') {
                    $this->info('Aggregate-only report saved to '.$path);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Inventory failed; no data was changed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    /** @param array<object> $rows */
    private function digest(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
