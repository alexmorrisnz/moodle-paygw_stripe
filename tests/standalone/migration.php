<?php
// phpcs:ignoreFile -- Minimal XMLDB adapters for executing the real email upgrade on SQLite.
// GPL v3 or later. Loaded by run.php.

use paygw_stripe\local\model\invoice;
use paygw_stripe\local\repository\invoice_repository;

define('XMLDB_TYPE_CHAR', 'char');
define('XMLDB_TYPE_TEXT', 'text');
define('XMLDB_TYPE_INTEGER', 'int');
define('XMLDB_NOTNULL', true);
class xmldb_table {
    public function __construct(public string $name) {}
}
class xmldb_field {
    public function __construct(public string $name, public string $type, public $length, public $unsigned,
        public $notnull, public $sequence, public $default, public $previous) {}
}
class schema_manager {
    public function __construct(private PDO $pdo) {}
    public function field_exists(xmldb_table $table, xmldb_field $field): bool {
        return in_array($field->name, array_column($this->pdo->query('PRAGMA table_info(' . $table->name . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
    }
    public function add_field(xmldb_table $table, xmldb_field $field): void {
        $sql = 'ALTER TABLE ' . $table->name . ' ADD COLUMN ' . $field->name . ' ' . ($field->type === 'int' ? 'INTEGER' : 'TEXT');
        if ($field->notnull) { $sql .= ' NOT NULL'; }
        if ($field->default !== null) { $sql .= ' DEFAULT ' . $this->pdo->quote($field->default); }
        $this->pdo->exec($sql);
    }
}
function upgrade_plugin_savepoint($result, $version, $type, $plugin) {
    $GLOBALS['savepoints'][] = [$result, $version, $type, $plugin];
}

test('Email upgrade preserves purchases, skips legacy emails and matches fresh installation', function() {
    global $api, $DB, $CFG, $testdir;
    $s = fresh(); $purchases = [];
    foreach (['billing', 'open', 'paid'] as $status) {
        [$id, $token] = start($s);
        if ($status !== 'billing') {
            $invoiceid = complete($s, $id, $token);
            if ($status === 'paid') { $api->paid($invoiceid); $s->process_event(event($invoiceid)); }
        }
        $purchases[] = [$id, $token, $status];
    }
    $schema = fn() => $DB->pdo->query('PRAGMA table_info(paygw_stripe_invoices)')->fetchAll(PDO::FETCH_ASSOC);
    $freshschema = $schema();
    foreach (['paymentmethods', 'paymentmethodstatus', 'banktransfercountry', 'timeemailsent', 'timeemailstarted', 'emailstatus'] as $field) {
        $DB->pdo->exec('ALTER TABLE paygw_stripe_invoices DROP COLUMN ' . $field);
    }
    $legacy = $DB->get_record('paygw_stripe_invoices', ['id' => $id]);
    check(invoice::from_record($legacy)->emailstatus === 'legacy');
    $before = count($api->requests); $sent = count($api->sent);
    $CFG->dirroot = $testdir . '/moodle';
    mkdir($CFG->dirroot . '/payment/gateway', 0700, true);
    symlink(dirname(__DIR__, 2), $CFG->dirroot . '/payment/gateway/stripe');
    try {
        require_once(__DIR__ . '/../../db/upgrade.php');
        $GLOBALS['savepoints'] = [];
        check(xmldb_paygw_stripe_upgrade(2026092400));
        check($GLOBALS['savepoints'] === [[true, 2026092500, 'paygw', 'stripe'], [true, 2026092501, 'paygw', 'stripe'], [true, 2026092502, 'paygw', 'stripe']]);
        check(count($api->requests) === $before, 'Upgrade must not contact Stripe or send legacy invoices');
        check($schema() === $freshschema && count($freshschema) === 31);
        // Safe when a previous upgrade was interrupted after adding some or all fields.
        check(xmldb_paygw_stripe_upgrade(2026092400) && $schema() === $freshschema);
    } finally {
        unlink($CFG->dirroot . '/payment/gateway/stripe');
        unset($CFG->dirroot);
    }
    $repo = new invoice_repository();
    foreach ($purchases as [$id, $token, $status]) {
        $record = $repo->find_by_id($id);
        check($record->status === $status && $record->emailstatus === 'legacy');
        check($record->timeemailstarted === null && $record->timeemailsent === null);
        check($record->banktransfercountry === null);
        complete($s, $id, $token);
    }
    check(count($api->sent) === $sent && $DB->count('payments') === 1 && $DB->count('enrolments') === 1);
    [$id, $token] = start($s); complete($s, $id, $token);
    check($repo->find_by_id($id)->emailstatus === 'sent' && count($api->sent) === $sent + 1);
});
