<?php
declare(strict_types=1);

require_once __DIR__ . '/material.php';

class Periferico extends Material
{
    private ?int $localId;

    public function __construct(
        ?int $id,
        string $nome,
        ?string $marca,
        ?string $modelo,
        ?string $dataCompra,
        ?int $localId,
        bool $isBroken = false,
        bool $isAbate = false,
        ?string $dataBroken = null,
        bool $disponivel = true,
        ?string $codigoInventario = null
    ) {
        parent::__construct($id, $nome, $marca, $modelo, $dataCompra, $isBroken, $isAbate, $dataBroken, $disponivel, $codigoInventario);

        $this->localId = $localId;
    }

    public function getTipo(): string
    {
        return 'periferico';
    }

    public function getLocalId(): ?int
    {
        return $this->localId;
    }

    public function setLocalId(?int $localId): void
    {
        $this->localId = $localId;
    }
}
