<?php
declare(strict_types=1);

namespace WooOps\Auditor\Checks;

use WooOps\Auditor\Store\StoreGateway;

interface CheckInterface
{
    /** Stable machine key, used as the JSON `checks` key. */
    public function key(): string;

    public function title(): string;

    /**
     * @return array{findings: list<\WooOps\Auditor\Audit\Finding>, data: array}
     */
    public function run(StoreGateway $store): array;
}
