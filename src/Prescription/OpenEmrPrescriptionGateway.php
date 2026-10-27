<?php

/**
 * Writes through OpenEMR's PrescriptionService rather than raw SQL, so uuid
 * registration and column handling stay core's problem. The service has no
 * update, so changes to an existing row are a bound UPDATE on a fixed list of
 * columns; deactivation mirrors what the service's delete does (active = 0).
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Prescription;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\PrescriptionService;
use Throwable;

class OpenEmrPrescriptionGateway implements PrescriptionGatewayInterface
{
    private const TABLE = 'prescriptions';

    /** The columns a later change to a LogiCoy medication may touch. */
    private const UPDATABLE = [
        'drug', 'note', 'drug_dosage_instructions', 'quantity', 'refills', 'start_date',
        'drug_info_erx', 'provider_id', 'encounter', 'substitute', 'date_modified', 'updated_by', 'active',
    ];

    private ?PrescriptionService $prescriptionService;

    public function __construct(?PrescriptionService $prescriptionService = null)
    {
        // Resolved lazily: constructing PrescriptionService pulls in OpenEMR's
        // procedural layer, which is not available at module load time.
        $this->prescriptionService = $prescriptionService;
    }

    public function insert(array $data): int
    {
        $service = $this->prescriptionService ??= new PrescriptionService();

        $result = $service->insert($data);

        if (!$result->isValid() || $result->hasErrors()) {
            throw new PrescriptionWriteException(
                'PrescriptionService rejected the LogiCoy prescription: '
                . implode('; ', array_keys($result->getValidationMessages()))
            );
        }

        $inserted = $result->getData()[0] ?? [];
        $prescriptionId = (int)($inserted['id'] ?? 0);

        if ($prescriptionId === 0) {
            throw new PrescriptionWriteException('PrescriptionService returned no prescription id');
        }

        return $prescriptionId;
    }

    public function update(int $prescriptionId, array $data): void
    {
        $set = [];
        $binds = [];
        foreach (self::UPDATABLE as $column) {
            if (array_key_exists($column, $data)) {
                $set[] = '`' . $column . '` = ?';
                $binds[] = $data[$column];
            }
        }
        if ($set === []) {
            return;
        }
        $binds[] = $prescriptionId;

        try {
            QueryUtils::sqlStatementThrowException(
                'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $set) . ' WHERE id = ?',
                $binds
            );
        } catch (Throwable $e) {
            throw new PrescriptionWriteException('Prescription ' . $prescriptionId . ' could not be updated', 0, $e);
        }
    }

    public function deactivate(int $prescriptionId): void
    {
        $this->update($prescriptionId, ['active' => 0, 'date_modified' => date('Y-m-d H:i:s')]);
    }
}
