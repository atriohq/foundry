<?php
declare(strict_types=1);

/**
 * Example daily service-renewal cron for Foundry.
 *
 * What it does:
 *  1. Finds active/expired services whose expires_at date has passed.
 *  2. Creates one renewal invoice and one linked renewal order per expiry.
 *  3. Gives the customer 30 days to pay the invoice.
 *  4. Marks an unpaid invoice overdue and suspends its service after 30 days.
 *
 * The original order supplies the example renewal price and currency because
 * the services table does not contain billing terms. A real application may
 * replace that part with its own product catalogue or provider pricing.
 *
 * The paid-order fulfilment path should extend expires_at and call the actual
 * provider renewal/reactivation API. That is deliberately application-specific.
 *
 * Run every day at midnight (change /var/www/foundry if necessary):
 * 0 0 * * * cd /var/www/foundry && /usr/bin/php bin/renew-services.php >> logs/renew-services.log 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Pinga\Db\PdoDataSource;
use Pinga\Db\PdoDatabase;

const RENEWAL_GRACE_DAYS = 30;

function cronLog(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$message}\n");
}

function databaseDate(DateTimeInterface $date): string
{
    return $date->format('Y-m-d H:i:s');
}

/**
 * The stable marker makes invoice generation idempotent. The same service
 * expiry will therefore never receive a second invoice on tomorrow's run.
 */
function renewalInvoiceNote(array $service, DateTimeImmutable $expiresAt): string
{
    return sprintf(
        'Automatic renewal for %s service #%d (expiry %s) [renewal:%d:%s]',
        (string)$service['type'],
        (int)$service['id'],
        databaseDate($expiresAt),
        (int)$service['id'],
        $expiresAt->format('YmdHis')
    );
}

// Prevent two overlapping cron processes from generating the same invoice.
$installation = realpath(__DIR__ . '/..') ?: __DIR__;
$lockFile = sys_get_temp_dir() . '/foundry-renew-services-' . sha1($installation) . '.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    throw new RuntimeException('Unable to create the renewal cron lock file.');
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    cronLog('Another renewal cron process is already running; exiting.');
    exit(0);
}

Dotenv::createImmutable(__DIR__ . '/../')->load();

// Use the same lightweight database setup as the other Foundry CLI scripts.
$dataSource = new PdoDataSource($_ENV['DB_DRIVER']);
$dataSource->setHostname($_ENV['DB_HOST']);
$dataSource->setPort((int)$_ENV['DB_PORT']);
$dataSource->setDatabaseName($_ENV['DB_DATABASE']);
$dataSource->setCharset('utf8mb4');
if ($_ENV['DB_USERNAME'] !== '') {
    $dataSource->setUsername($_ENV['DB_USERNAME']);
}
if ($_ENV['DB_PASSWORD'] !== '') {
    $dataSource->setPassword($_ENV['DB_PASSWORD']);
}

$db = PdoDatabase::fromDataSource($dataSource);
$now = new DateTimeImmutable('now');
$nowForDatabase = databaseDate($now);
$createdInvoices = 0;
$suspendedServices = 0;
$hadErrors = false;

try {
    /*
     * Reuse the original order's amount and currency for this framework
     * example. LEFT JOIN lets us report incomplete legacy service records.
     */
    $services = $db->select(
        'SELECT s.id, s.user_id, s.type, s.expires_at,
                o.amount_due, o.currency, o.service_data AS original_service_data
         FROM services s
         LEFT JOIN orders o ON o.id = s.order_id
         WHERE s.expires_at IS NOT NULL
           AND s.expires_at <= ?
           AND s.status IN (?, ?)',
        [$nowForDatabase, 'active', 'expired']
    );

    foreach ($services ?? [] as $service) {
        try {
            $serviceId = (int)$service['id'];
            $expiresAt = new DateTimeImmutable((string)$service['expires_at']);
            $invoiceNote = renewalInvoiceNote($service, $expiresAt);

            // Fast idempotency check before doing any additional work.
            $existingInvoiceId = $db->selectValue(
                'SELECT id FROM invoices WHERE notes = ? LIMIT 1',
                [$invoiceNote]
            );
            if ($existingInvoiceId) {
                continue;
            }

            if (!isset($service['amount_due']) || !is_numeric($service['amount_due'])) {
                throw new RuntimeException('The original order has no usable renewal price.');
            }

            $amount = number_format((float)$service['amount_due'], 2, '.', '');
            if ((float)$amount <= 0) {
                throw new RuntimeException('The original order renewal price must be positive.');
            }

            $currency = strtoupper(trim((string)($service['currency'] ?? '')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new RuntimeException('The original order has no valid currency.');
            }

            $billingContactId = (int)$db->selectValue(
                'SELECT id FROM users_contact
                 WHERE user_id = ? AND type = ?
                 ORDER BY id ASC LIMIT 1',
                [(int)$service['user_id'], 'billing']
            );
            if ($billingContactId < 1) {
                throw new RuntimeException('The service owner has no billing contact.');
            }

            // Preserve useful product data from the original order for display.
            $renewalData = json_decode(
                (string)($service['original_service_data'] ?? ''),
                true
            );
            if (!is_array($renewalData)) {
                $renewalData = [];
            }
            $renewalData['type'] = $service['type'] === 'domain'
                ? 'domain_renew'
                : 'service_renew';
            $renewalData['billing_action'] = 'renewal';
            $renewalData['renewal_service_id'] = $serviceId;
            $renewalData['renewal_for'] = databaseDate($expiresAt);
            $renewalData['description'] = sprintf(
                'Renewal of %s service #%d',
                (string)$service['type'],
                $serviceId
            );
            $renewalDataJson = json_encode(
                $renewalData,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );

            $dueAt = $now->modify('+' . RENEWAL_GRACE_DAYS . ' days');

            $db->beginTransaction();

            // Recheck inside the transaction in case this installation was busy.
            $existingInvoiceId = $db->selectValue(
                'SELECT id FROM invoices WHERE notes = ? LIMIT 1',
                [$invoiceNote]
            );
            if ($existingInvoiceId) {
                $db->commit();
                continue;
            }

            $db->insert('invoices', [
                'user_id' => (int)$service['user_id'],
                'invoice_type' => 'regular',
                'billing_contact_id' => $billingContactId,
                'issue_date' => $nowForDatabase,
                'due_date' => databaseDate($dueAt),
                'total_amount' => $amount,
                'payment_status' => 'unpaid',
                'notes' => $invoiceNote,
                'created_at' => $nowForDatabase,
                'updated_at' => $nowForDatabase,
            ]);

            // Looking up by the stable note works consistently on every DB driver.
            $invoiceId = (int)$db->selectValue(
                'SELECT id FROM invoices WHERE notes = ? ORDER BY id DESC LIMIT 1',
                [$invoiceNote]
            );
            if ($invoiceId < 1) {
                throw new RuntimeException('The renewal invoice could not be retrieved.');
            }

            // Foundry currently uses the invoice ID as its visible invoice number.
            $db->update('invoices', ['invoice_number' => (string)$invoiceId], ['id' => $invoiceId]);

            // Orders act as invoice line items in the current Foundry schema.
            $db->insert('orders', [
                'user_id' => (int)$service['user_id'],
                'service_type' => (string)$service['type'],
                'service_data' => $renewalDataJson,
                'status' => 'pending',
                'amount_due' => $amount,
                'currency' => $currency,
                'invoice_id' => $invoiceId,
                'created_at' => $nowForDatabase,
            ]);

            $db->insert('service_logs', [
                'service_id' => $serviceId,
                'event' => 'renewal_invoice_created',
                'actor_type' => 'system',
                'actor_id' => null,
                'details' => json_encode([
                    'invoice_id' => $invoiceId,
                    'due_date' => databaseDate($dueAt),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $nowForDatabase,
            ]);

            $db->commit();
            $createdInvoices++;
            cronLog("Created renewal invoice #{$invoiceId} for service #{$serviceId}.");

            // Example integration point: enqueue the invoice email after commit.
        } catch (Throwable $exception) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $hadErrors = true;
            cronLog(
                'ERROR creating invoice for service #' .
                (int)($service['id'] ?? 0) . ': ' . $exception->getMessage()
            );
        }
    }

    /*
     * Only renewal orders contain renewal_service_id. Ordinary overdue
     * invoices are therefore ignored and cannot suspend unrelated services.
     */
    $overdueOrders = $db->select(
        'SELECT i.id AS invoice_id, i.user_id, i.due_date,
                i.payment_status, o.service_data
         FROM invoices i
         INNER JOIN orders o ON o.invoice_id = i.id
         WHERE i.payment_status IN (?, ?)
           AND i.due_date IS NOT NULL
           AND i.due_date <= ?',
        ['unpaid', 'overdue', $nowForDatabase]
    );

    foreach ($overdueOrders ?? [] as $overdueOrder) {
        $serviceData = json_decode((string)$overdueOrder['service_data'], true);
        if (
            !is_array($serviceData)
            || ($serviceData['billing_action'] ?? null) !== 'renewal'
        ) {
            continue;
        }

        $serviceId = filter_var(
            $serviceData['renewal_service_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($serviceId === false) {
            $hadErrors = true;
            cronLog(
                'ERROR: Renewal invoice #' . (int)$overdueOrder['invoice_id'] .
                ' contains an invalid service ID.'
            );
            continue;
        }

        try {
            $invoiceId = (int)$overdueOrder['invoice_id'];
            $db->beginTransaction();

            // Re-read inside the transaction so a recently paid invoice is skipped.
            $invoice = $db->selectRow(
                'SELECT user_id, payment_status, due_date
                 FROM invoices WHERE id = ? LIMIT 1',
                [$invoiceId]
            );
            if (!$invoice || !in_array($invoice['payment_status'], ['unpaid', 'overdue'], true)) {
                $db->commit();
                continue;
            }

            $dueAt = new DateTimeImmutable((string)$invoice['due_date']);
            if ($dueAt > $now) {
                $db->commit();
                continue;
            }

            if ($invoice['payment_status'] === 'unpaid') {
                $db->exec(
                    'UPDATE invoices SET payment_status = ?, updated_at = ?
                     WHERE id = ? AND payment_status = ?',
                    ['overdue', $nowForDatabase, $invoiceId, 'unpaid']
                );
            }

            // The user_id condition prevents malformed order JSON crossing accounts.
            $updated = $db->exec(
                'UPDATE services SET status = ?, updated_at = ?
                 WHERE id = ? AND user_id = ? AND status IN (?, ?)',
                [
                    'suspended',
                    $nowForDatabase,
                    $serviceId,
                    (int)$invoice['user_id'],
                    'active',
                    'expired',
                ]
            );

            if ((int)$updated > 0) {
                $db->insert('service_logs', [
                    'service_id' => $serviceId,
                    'event' => 'suspended',
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'details' => json_encode([
                        'reason' => 'renewal_invoice_unpaid_for_30_days',
                        'invoice_id' => $invoiceId,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $nowForDatabase,
                ]);
            }

            $db->commit();

            if ((int)$updated > 0) {
                $suspendedServices++;
                cronLog("Suspended service #{$serviceId}; invoice #{$invoiceId} is overdue.");

                // Example integration point: call the provider suspend API here.
            }
        } catch (Throwable $exception) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $hadErrors = true;
            cronLog(
                'ERROR processing overdue invoice #' .
                (int)($overdueOrder['invoice_id'] ?? 0) . ': ' . $exception->getMessage()
            );
        }
    }

    cronLog(
        "Finished: {$createdInvoices} invoice(s) created, " .
        "{$suspendedServices} service(s) suspended."
    );
} catch (Throwable $exception) {
    if ($db->isTransactionActive()) {
        $db->rollBack();
    }
    cronLog('FATAL: ' . $exception->getMessage());
    exit(1);
}

exit($hadErrors ? 1 : 0);
