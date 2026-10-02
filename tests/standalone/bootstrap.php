<?php
// phpcs:ignoreFile -- Isolated CLI fixtures deliberately mirror external interfaces.
// Standalone test adapters. These are not loaded by Moodle or PHPUnit.
// GPL v3 or later. No network requests or real Stripe credentials are used.

namespace {
    if (PHP_SAPI !== 'cli') {
        die();
    }
    define('MOODLE_INTERNAL', true);
    define('HOURSECS', 3600);
    error_reporting(E_ALL);
    set_error_handler(function($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    require_once(__DIR__ . '/../../.extlib/stripe-php/init.php');
    spl_autoload_register(function($class) {
        if (str_starts_with($class, 'paygw_stripe\\')) {
            require_once(__DIR__ . '/../../classes/' . str_replace('\\', '/', substr($class, 13)) . '.php');
        }
    });
    class moodle_exception extends \RuntimeException {
        public function __construct($code, $component = '') {
            parent::__construct($code);
        }
    }
    class moodle_url {
        public function __construct(private string $path, private array $params = []) {
        }
        public function out($escaped = true): string {
            global $CFG;
            return $CFG->wwwroot . $this->path . '?' . http_build_query($this->params);
        }
    }
    function fullname($user) { return $user->firstname . ' ' . $user->lastname; }
    function get_string($key, $component = '', $data = null) { return $key; }
    function get_config($component, $name = null) { return $name ? '' : (object)['version' => '2026092500']; }
    function current_language() { return 'en'; }
    function sesskey() { return 'test-session-key'; }

    /** Real SQLite constraints and transactions behind the minimal Moodle DB interface. */
    class moodle_database {
        public \PDO $pdo;
        public bool $failmarkemailsent = false;
        public bool $failmarkmethodsready = false;
        public function __construct(public string $path) {
            $this->pdo = new \PDO('sqlite:' . $path);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA busy_timeout=10000');
        }
        public function install(): void {
            $schema = simplexml_load_file(__DIR__ . '/../../db/install.xml');
            foreach ($schema->TABLES->TABLE as $table) {
                $name = (string)$table['NAME'];
                $columns = [];
                foreach ($table->FIELDS->FIELD as $field) {
                    $column = (string)$field['NAME'];
                    $sql = $column . ' ' . ($field['TYPE'] == 'int' ? 'INTEGER' : 'TEXT');
                    if ($field['SEQUENCE'] == 'true') {
                        $sql .= ' PRIMARY KEY AUTOINCREMENT';
                    } else if ($field['NOTNULL'] == 'true') {
                        $sql .= ' NOT NULL';
                    }
                    if (isset($field['DEFAULT'])) {
                        $sql .= ' DEFAULT ' . $this->pdo->quote((string)$field['DEFAULT']);
                    }
                    $columns[] = $sql;
                }
                $this->pdo->exec('CREATE TABLE ' . $name . ' (' . implode(',', $columns) . ')');
                foreach ($table->INDEXES->INDEX ?? [] as $index) {
                    $this->pdo->exec('CREATE ' . ($index['UNIQUE'] == 'true' ? 'UNIQUE ' : '') . 'INDEX ' .
                        $name . '_' . $index['NAME'] . ' ON ' . $name . ' (' . $index['FIELDS'] . ')');
                }
            }
            $this->pdo->exec('CREATE UNIQUE INDEX customers_user ON paygw_stripe_customers(userid)');
            $this->pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, accountid, component, paymentarea, itemid, userid, amount, currency, gateway)');
            $this->pdo->exec('CREATE TABLE enrolments (id INTEGER PRIMARY KEY, paymentid, userid, itemid)');
        }
        public function get_record($table, $conditions) {
            $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(fn($k) => "$k = ?", array_keys($conditions)));
            $s = $this->pdo->prepare($sql);
            $s->execute(array_values($conditions));
            return $s->fetch(\PDO::FETCH_OBJ);
        }
        public function insert_record($table, $record): int {
            $values = (array)$record;
            $s = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (' .
                implode(',', array_fill(0, count($values), '?')) . ')');
            $s->execute(array_values($values));
            return (int)$this->pdo->lastInsertId();
        }
        public function update_record($table, $record): void {
            if ($this->failmarkmethodsready && $table === 'paygw_stripe_invoices' && ($record->paymentmethodstatus ?? '') === 'ready') {
                $this->failmarkmethodsready = false;
                throw new \RuntimeException('Simulated database failure after configuring payment methods');
            }
            if ($this->failmarkemailsent && $table === 'paygw_stripe_invoices' && ($record->emailstatus ?? '') === 'sent') {
                $this->failmarkemailsent = false;
                throw new \RuntimeException('Simulated database failure after sending');
            }
            $values = (array)$record;
            $id = $values['id'];
            unset($values['id']);
            $s = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($values))) . ' WHERE id = ?');
            $s->execute([...array_values($values), $id]);
        }
        public function delete_records($table, $conditions): void {
            $s = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(fn($k) => "$k = ?", array_keys($conditions))));
            $s->execute(array_values($conditions));
        }
        public function count(string $table): int {
            return (int)$this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        public function get_manager() { return new schema_manager($this->pdo); }
        public function start_delegated_transaction() {
            $this->pdo->beginTransaction();
            return new class($this->pdo) {
                public function __construct(private \PDO $pdo) {}
                public function allow_commit(): void { $this->pdo->commit(); }
                public function rollback($e): void { $this->pdo->rollBack(); throw $e; }
            };
        }
    }
}
namespace core\lock {
    class lock {
        public function __construct(private $handle) {}
        public function release(): void { flock($this->handle, LOCK_UN); fclose($this->handle); }
    }
    class lock_config {
        public static function get_lock_factory($name) {
            return new class {
                public function get_lock($key, $timeout) {
                    global $testdir;
                    $h = fopen($testdir . '/' . hash('sha256', $key) . '.lock', 'c');
                    if (!flock($h, LOCK_EX)) { return false; }
                    return new lock($h);
                }
            };
        }
    }
}
namespace core_payment {
    class gateway {}
    class account {
        public function __construct(private int $id) {}
        public function get_gateways($enabled) {
            return ['stripe' => new class {
                public function get_configuration() { return ['apikey' => 'pk_test_fake', 'secretkey' => 'sk_test_fake']; }
            }];
        }
    }
    class helper {
        public static string $failure = '';
        public static function save_payment($accountid, $component, $paymentarea, $itemid, $userid, $amount, $currency, $gateway) {
            global $DB;
            if (self::$failure === 'payment') { throw new \RuntimeException('injected payment failure'); }
            return $DB->insert_record('payments', (object)compact('accountid', 'component', 'paymentarea', 'itemid', 'userid', 'amount', 'currency', 'gateway'));
        }
        public static function deliver_order($component, $paymentarea, $itemid, $paymentid, $userid) {
            global $DB;
            $DB->insert_record('enrolments', (object)compact('paymentid', 'userid', 'itemid'));
            if (self::$failure === 'delivery') { throw new \RuntimeException('injected delivery failure'); }
            return self::$failure !== 'false';
        }
    }
}
namespace core_payment\local\entities {
    class payable {
        public function __construct(private float $amount, private string $currency, private int $accountid) {}
        public function get_amount(): float { return $this->amount; }
        public function get_currency(): string { return $this->currency; }
        public function get_account_id(): int { return $this->accountid; }
    }
}
