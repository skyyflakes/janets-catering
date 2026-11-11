<?php
/**
 * PostgreSQL (Supabase) versions of the activity-log triggers and of the tables used only when hosted.
 * The MySQL triggers are built in includes/activity_logger.php; these behave the same way:
 *   - every insert, update and delete of an audited table writes one activity_logs row with old and new values
 *     (sensitive columns hidden), and classifies updates as ARCHIVE, RESTORE, STATUS_CHANGE or UPDATE;
 *   - activity_logs rows are write-once (only the OWNER may archive or restore them), activity_log_audit and
 *     backup_history are append-only, and important changes notify active Owners and Admins.
 * The audit context comes from the app.* connection settings written by applyAuditContext() / jq_set_audit_vars().
 */

/** Tables that exist only for hosting: sign-in sessions and uploaded files kept in the database. */
function jq_pg_hosted_tables_sql(): string
{
    return "CREATE TABLE IF NOT EXISTS app_sessions (
    id varchar(128) PRIMARY KEY,
    data text NOT NULL DEFAULT '',
    updated_at integer NOT NULL
);
CREATE INDEX IF NOT EXISTS app_sessions__updated ON app_sessions (updated_at);
CREATE TABLE IF NOT EXISTS stored_files (
    path varchar(500) PRIMARY KEY,
    mime varchar(100) NOT NULL,
    size integer NOT NULL,
    data bytea NOT NULL,
    created_at timestamp(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE app_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE stored_files ENABLE ROW LEVEL SECURITY;";
}

/** SQL string literal for PostgreSQL. */
function jq_pg_str(?string $s): string
{
    return $s === null ? 'NULL' : "'" . str_replace("'", "''", $s) . "'";
}

/**
 * All CREATE FUNCTION / CREATE TRIGGER statements.
 * @param array $columns [table => ['columns' => [...], 'pk' => 'id']] of the live database
 */
function jq_pg_trigger_statements(array $columns): array
{
    $sql = [];
    $sql[] = "CREATE OR REPLACE FUNCTION jq_app(k text) RETURNS text LANGUAGE sql STABLE AS \$f\$ SELECT NULLIF(current_setting('app.' || k, true), '') \$f\$";
    $sql[] = "CREATE OR REPLACE FUNCTION jq_app_user() RETURNS integer LANGUAGE sql STABLE AS \$f\$
        SELECT u.id FROM users u WHERE jq_app('user_id') ~ '^[0-9]+\$' AND u.id = jq_app('user_id')::integer LIMIT 1 \$f\$";

    // One function for every audited table; the table's settings are passed as trigger arguments:
    // 0 module, 1 entity, 2 primary key, 3 label columns, 4 sensitive columns, 5 ignored columns (comma-separated)
    $sql[] = <<<'SQL'
CREATE OR REPLACE FUNCTION jq_audit_row() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE
    v_module text := TG_ARGV[0];
    v_entity text := TG_ARGV[1];
    v_pk text := TG_ARGV[2];
    v_labels text[] := string_to_array(NULLIF(TG_ARGV[3], ''), ',');
    v_sensitive text[] := COALESCE(string_to_array(NULLIF(TG_ARGV[4], ''), ','), '{}');
    v_ignored text[] := COALESCE(string_to_array(NULLIF(TG_ARGV[5], ''), ','), '{}');
    v_old jsonb; v_new jsonb; v_row jsonb; v_key text;
    v_old_out jsonb := '{}'; v_new_out jsonb := '{}';
    v_changed text[] := '{}';
    v_action text; v_verb text; v_label text; v_desc text; v_extra text := '';
BEGIN
    IF TG_OP <> 'INSERT' THEN v_old := to_jsonb(OLD); END IF;
    IF TG_OP <> 'DELETE' THEN v_new := to_jsonb(NEW); END IF;
    v_row := COALESCE(v_new, v_old);

    SELECT NULLIF(string_agg(v_row ->> l, ' - ' ORDER BY ord), '') INTO v_label
      FROM unnest(v_labels) WITH ORDINALITY AS t(l, ord) WHERE v_row ->> l IS NOT NULL;

    IF TG_OP = 'UPDATE' THEN
        FOR v_key IN SELECT jsonb_object_keys(v_new) LOOP
            IF NOT (v_key = ANY (v_ignored)) AND (v_old -> v_key) IS DISTINCT FROM (v_new -> v_key) THEN
                v_changed := v_changed || v_key;
            END IF;
        END LOOP;
        IF cardinality(v_changed) = 0 THEN RETURN NULL; END IF;
        FOREACH v_key IN ARRAY v_changed LOOP
            v_old_out := v_old_out || jsonb_build_object(v_key, CASE WHEN v_key = ANY (v_sensitive) THEN to_jsonb('[protected]'::text) ELSE to_jsonb(v_old ->> v_key) END);
            v_new_out := v_new_out || jsonb_build_object(v_key, CASE WHEN v_key = ANY (v_sensitive) THEN to_jsonb('[changed]'::text) ELSE to_jsonb(v_new ->> v_key) END);
        END LOOP;
        v_action := CASE
            WHEN v_new ? 'is_archived' AND COALESCE((v_old ->> 'is_archived')::int, 0) = 0 AND COALESCE((v_new ->> 'is_archived')::int, 0) = 1 THEN 'ARCHIVE'
            WHEN v_new ? 'is_archived' AND COALESCE((v_old ->> 'is_archived')::int, 0) = 1 AND COALESCE((v_new ->> 'is_archived')::int, 0) = 0 THEN 'RESTORE'
            WHEN v_new ? 'status' AND v_changed = ARRAY['status'] THEN 'STATUS_CHANGE'
            ELSE 'UPDATE' END;
        v_verb := CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END;
        IF v_new ? 'status' AND (v_old -> 'status') IS DISTINCT FROM (v_new -> 'status') THEN
            v_extra := v_extra || ' - status: ' || COALESCE(v_old ->> 'status', 'none') || ' -> ' || COALESCE(v_new ->> 'status', 'none');
        END IF;
        IF v_new ? 'is_active' AND (v_old -> 'is_active') IS DISTINCT FROM (v_new -> 'is_active') THEN
            v_extra := v_extra || CASE WHEN (v_new ->> 'is_active') = '1' THEN ' - activated' ELSE ' - deactivated' END;
        END IF;
        FOREACH v_key IN ARRAY v_sensitive LOOP
            IF v_new ? v_key AND (v_old -> v_key) IS DISTINCT FROM (v_new -> v_key) THEN v_extra := v_extra || ' - ' || v_key || ' changed'; END IF;
        END LOOP;
        v_desc := v_verb || ' ' || v_entity || ' #' || (v_new ->> v_pk) || COALESCE(' (' || v_label || ')', '') || v_extra;
    ELSE
        FOR v_key IN SELECT jsonb_object_keys(v_row) LOOP
            IF TG_OP = 'INSERT' THEN
                v_new_out := v_new_out || jsonb_build_object(v_key, CASE WHEN v_key = ANY (v_sensitive) THEN to_jsonb('[protected]'::text) ELSE to_jsonb(v_row ->> v_key) END);
            ELSE
                v_old_out := v_old_out || jsonb_build_object(v_key, CASE WHEN v_key = ANY (v_sensitive) THEN to_jsonb('[protected]'::text) ELSE to_jsonb(v_row ->> v_key) END);
            END IF;
        END LOOP;
        v_action := CASE TG_OP WHEN 'INSERT' THEN 'CREATE' ELSE 'DELETE' END;
        v_desc := CASE TG_OP WHEN 'INSERT' THEN 'Created ' ELSE 'Deleted ' END || v_entity || ' #' || (v_row ->> v_pk) || COALESCE(' (' || v_label || ')', '');
    END IF;

    INSERT INTO activity_logs (user_id, username, user_role, action, module, record_table, record_id, record_label, description,
                               old_values, new_values, ip_address, user_agent, page, source)
    VALUES (jq_app_user(), left(COALESCE(jq_app('username'), current_user), 100), jq_app('role'), v_action, v_module, TG_TABLE_NAME,
            v_row ->> v_pk, left(v_label, 255), v_desc,
            CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE v_old_out::text END, CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE v_new_out::text END,
            jq_app('ip'), jq_app('ua'), jq_app('page'), CASE WHEN jq_app('context') = '1' THEN 'app' ELSE 'direct_db' END);
    RETURN NULL;
END $f$
SQL;

    $sql[] = "CREATE OR REPLACE FUNCTION jq_deny() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN RAISE EXCEPTION '%', TG_ARGV[0]; END \$f\$";

    $sql[] = <<<'SQL'
CREATE OR REPLACE FUNCTION jq_logs_before_insert() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
    NEW.created_at := localtimestamp(3); NEW.is_archived := 0; NEW.archived_at := NULL; NEW.archived_by := NULL;
    NEW.restored_at := NULL; NEW.restored_by := NULL; NEW.edited_at := NULL; NEW.edited_by := NULL; NEW.edit_count := 0;
    IF NEW.source = 'manual' AND COALESCE(jq_app('role'), '') <> 'OWNER' THEN
        RAISE EXCEPTION 'Only the OWNER can create manual activity log entries.';
    END IF;
    RETURN NEW;
END $f$
SQL;

    $sql[] = <<<'SQL'
CREATE OR REPLACE FUNCTION jq_logs_after_insert() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
    IF NEW.source = 'manual' THEN
        INSERT INTO activity_log_audit (log_id, operation, old_snapshot, new_snapshot, reason, performed_by, performed_by_username, performed_by_role, ip_address)
        VALUES (NEW.id, 'CREATE', NULL, jq_log_snapshot(NEW), left(COALESCE(jq_app('log_reason'), 'Manual entry'), 500),
                jq_app_user(), left(jq_app('username'), 100), jq_app('role'), jq_app('ip'));
    END IF;
    BEGIN
        -- A notification problem must never block the business operation being logged.
        IF NEW.action IN ('CREATE','UPDATE','STATUS_CHANGE','ARCHIVE','RESTORE','DELETE','BACKUP_CREATE','BACKUP_RESTORE','BACKUP_FAILED','LOG_ARCHIVE','LOG_RESTORE','RETENTION_PURGE') THEN
            INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type, actor_user_id, actor_username, activity_log_id)
            SELECT u.id,
                   CASE WHEN NEW.record_table IN ('event_bookings','booking_payments','booking_attachments','event_types','clients') THEN 'event'
                        WHEN NEW.record_table = 'reports' THEN 'report'
                        WHEN NEW.record_table = 'client_surveys' THEN 'feedback'
                        WHEN NEW.record_table IN ('foodstocks','foodstocks_categories','menu_packages','menu_items','menu_bundles') THEN 'inventory'
                        ELSE 'system' END,
                   CASE WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'CREATE' THEN 'New booking'
                        WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'STATUS_CHANGE' THEN 'Booking status changed'
                        WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'UPDATE' THEN 'Booking updated'
                        WHEN NEW.record_table = 'users' AND NEW.action = 'CREATE' THEN 'New user account'
                        WHEN NEW.record_table = 'users' THEN 'User account changed'
                        WHEN NEW.record_table IN ('menu_packages','menu_items','menu_bundles') THEN 'Menu or package changed'
                        WHEN NEW.action = 'BACKUP_CREATE' THEN 'Database backup created'
                        WHEN NEW.action = 'BACKUP_RESTORE' THEN 'Database restored'
                        WHEN NEW.action = 'BACKUP_FAILED' THEN 'Backup operation failed'
                        WHEN NEW.action IN ('LOG_ARCHIVE','LOG_RESTORE') THEN 'Activity logs managed'
                        ELSE COALESCE(NEW.module, 'System') || ' record changed' END,
                   COALESCE(NEW.description, ''),
                   CASE WHEN NEW.record_id ~ '^[0-9]{1,9}$' THEN NEW.record_id::integer END,
                   NEW.record_table, NEW.user_id, NEW.username, NEW.id
              FROM users u
             WHERE u.role IN ('OWNER','ADMIN') AND u.status = 'active' AND u.is_verified = 1 AND u.is_archived = 0
            ON CONFLICT DO NOTHING;
        END IF;
    EXCEPTION WHEN OTHERS THEN
        NULL;
    END;
    RETURN NULL;
END $f$
SQL;

    $sql[] = "CREATE OR REPLACE FUNCTION jq_log_snapshot(r activity_logs) RETURNS text LANGUAGE sql STABLE AS \$f\$
        SELECT json_build_object('action', r.action, 'module', r.module, 'description', r.description, 'record_label', r.record_label,
                                 'notes', r.notes, 'is_archived', r.is_archived, 'edit_count', r.edit_count)::text \$f\$";

    $sql[] = <<<'SQL'
CREATE OR REPLACE FUNCTION jq_logs_before_update() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE v_op text;
BEGIN
    IF COALESCE(jq_app('log_mgmt'), '0') <> '1' OR COALESCE(jq_app('role'), '') <> 'OWNER' THEN
        RAISE EXCEPTION 'Activity log records are protected. Only the OWNER can archive them through the Activity Log module.';
    END IF;
    IF (OLD.user_id, OLD.username, OLD.user_role, OLD.created_at, OLD.ip_address, OLD.user_agent, OLD.record_table, OLD.record_id, OLD.record_label,
        OLD.old_values, OLD.new_values, OLD.action, OLD.module, OLD.description, OLD.notes, OLD.source, OLD.page)
       IS DISTINCT FROM
       (NEW.user_id, NEW.username, NEW.user_role, NEW.created_at, NEW.ip_address, NEW.user_agent, NEW.record_table, NEW.record_id, NEW.record_label,
        NEW.old_values, NEW.new_values, NEW.action, NEW.module, NEW.description, NEW.notes, NEW.source, NEW.page) THEN
        RAISE EXCEPTION 'Activity log records cannot be edited.';
    END IF;
    IF COALESCE(OLD.is_archived, 0) = 0 AND COALESCE(NEW.is_archived, 0) = 1 THEN
        v_op := 'ARCHIVE'; NEW.archived_at := localtimestamp(3); NEW.archived_by := jq_app_user();
        NEW.restored_at := OLD.restored_at; NEW.restored_by := OLD.restored_by;
    ELSIF COALESCE(OLD.is_archived, 0) = 1 AND COALESCE(NEW.is_archived, 0) = 0 THEN
        v_op := 'RESTORE'; NEW.restored_at := localtimestamp(3); NEW.restored_by := jq_app_user();
        NEW.archived_at := OLD.archived_at; NEW.archived_by := OLD.archived_by;
    ELSE
        RAISE EXCEPTION 'Activity log records cannot be edited.';
    END IF;
    NEW.edited_at := OLD.edited_at; NEW.edited_by := OLD.edited_by; NEW.edit_count := OLD.edit_count;
    INSERT INTO activity_log_audit (log_id, operation, old_snapshot, new_snapshot, reason, performed_by, performed_by_username, performed_by_role, ip_address)
    VALUES (OLD.id, v_op, jq_log_snapshot(OLD), jq_log_snapshot(NEW), left(jq_app('log_reason'), 500),
            jq_app_user(), left(jq_app('username'), 100), jq_app('role'), jq_app('ip'));
    RETURN NEW;
END $f$
SQL;

    $trg = static fn(string $name, string $when, string $table, string $fn) => "DROP TRIGGER IF EXISTS $name ON $table;\nCREATE TRIGGER $name $when ON $table FOR EACH ROW EXECUTE FUNCTION $fn";
    $sql[] = $trg('trg_jq_activity_logs_bi', 'BEFORE INSERT', 'activity_logs', 'jq_logs_before_insert()');
    $sql[] = $trg('trg_jq_activity_logs_ai', 'AFTER INSERT', 'activity_logs', 'jq_logs_after_insert()');
    $sql[] = $trg('trg_jq_activity_logs_bu', 'BEFORE UPDATE', 'activity_logs', 'jq_logs_before_update()');
    $sql[] = $trg('trg_jq_activity_logs_bd', 'BEFORE DELETE', 'activity_logs', "jq_deny('Activity log records cannot be deleted. Archive them instead.')");
    $sql[] = $trg('trg_jq_log_audit_bu', 'BEFORE UPDATE', 'activity_log_audit', "jq_deny('The activity log audit trail is append-only.')");
    $sql[] = $trg('trg_jq_log_audit_bd', 'BEFORE DELETE', 'activity_log_audit', "jq_deny('The activity log audit trail is append-only.')");
    $sql[] = $trg('trg_jq_backup_history_bu', 'BEFORE UPDATE', 'backup_history', "jq_deny('Backup history is append-only.')");
    $sql[] = $trg('trg_jq_backup_history_bd', 'BEFORE DELETE', 'backup_history', "jq_deny('Backup history is append-only.')");

    $ignored = implode(',', jq_audit_ignored_columns());
    foreach (jq_audited_tables() as $table => $cfg) {
        $info = $columns[$table] ?? null;
        if (!$info || empty($info['pk'])) {
            continue;
        }
        $args = implode(', ', array_map('jq_pg_str', [$cfg['module'], $cfg['entity'], $info['pk'],
            implode(',', array_values(array_intersect($cfg['label'] ?? [], $info['columns']))), implode(',', $cfg['sensitive'] ?? []), $ignored]));
        $sql[] = $trg("trg_jq_{$table}_audit", 'AFTER INSERT OR UPDATE OR DELETE', $table, "jq_audit_row($args)");
    }
    return $sql;
}

/** Live column layout of the PostgreSQL tables: [table => ['columns' => [...], 'pk' => ...]]. */
function jq_pg_table_columns(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT c.table_name, c.column_name FROM information_schema.columns c
                           WHERE c.table_schema = current_schema() ORDER BY c.table_name, c.ordinal_position") as $r) {
        $out[$r['table_name']]['columns'][] = $r['column_name'];
    }
    foreach ($pdo->query("SELECT tc.table_name, kcu.column_name FROM information_schema.table_constraints tc
                            JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
                           WHERE tc.table_schema = current_schema() AND tc.constraint_type = 'PRIMARY KEY' ORDER BY kcu.ordinal_position") as $r) {
        $out[$r['table_name']]['pk'] ??= $r['column_name'];
    }
    return $out;
}

/** (Re)creates the hosted tables and every activity-log function and trigger. */
function jq_pg_install_triggers(PDO $pdo): void
{
    $pdo->exec(jq_pg_hosted_tables_sql());
    foreach (jq_pg_trigger_statements(jq_pg_table_columns($pdo)) as $stmt) {
        $pdo->exec($stmt);
    }
}
