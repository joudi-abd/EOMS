<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A sensitive change made by a module that may not depend on Identity (see deptrac.yaml). Identity listens and
 * writes it to the audit log with AuditLogger, inside the same transaction when one is open.
 */
class AuditableAction
{
    public function __construct(
        public string $action,
        public ?Model $auditable = null,
        public ?array $old = null,
        public ?array $new = null,
    ) {}
}
