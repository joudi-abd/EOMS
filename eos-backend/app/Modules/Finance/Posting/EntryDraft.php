<?php

namespace App\Modules\Finance\Posting;

/** An entry before posting: what LedgerService::postEntry() validates, numbers and writes. */
final readonly class EntryDraft
{
    /** @param  list<LineDraft>  $lines */
    public function __construct(
        public string $date,
        public int $branchId,
        public string $description,
        public array $lines,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
    ) {}
}
