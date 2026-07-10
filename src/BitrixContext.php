<?php

declare(strict_types=1);

namespace Keyzy\Pii;

final class BitrixContext
{
    public function __construct(
        public readonly object $connection,
        public readonly object $sqlHelper,
        public readonly string $documentRoot,
        public readonly string $databaseName,
        public readonly string $databaseHost,
        public readonly string $fingerprint,
        public readonly string $runtimeMode = 'bitrix',
    ) {
    }
}
