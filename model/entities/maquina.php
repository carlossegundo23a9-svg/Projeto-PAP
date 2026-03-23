<?php
declare(strict_types=1);

require_once __DIR__ . '/material.php';

class Maquina extends Material
{
    private ?string $mac;
    private ?string $numSerie;
    private ?int $localId;

    public function __construct(
        ?int $id,
        string $nome,
        ?string $marca,
        ?string $modelo,
        ?string $dataCompra,
        ?int $localId,
        ?string $mac = null,
        ?string $numSerie = null,
        bool $isBroken = false,
        bool $isAbate = false,
        ?string $dataBroken = null,
        bool $disponivel = true,
        ?string $codigoInventario = null
    ) {
        parent::__construct($id, $nome, $marca, $modelo, $dataCompra, $isBroken, $isAbate, $dataBroken, $disponivel, $codigoInventario);

        $this->localId = $localId;
        $this->mac = $this->sanitizeNullableString($mac);
        $this->numSerie = $this->sanitizeNullableString($numSerie);
    }

    public function getTipo(): string
    {
        return 'maquina';
    }

    public function getLocalId(): ?int
    {
        return $this->localId;
    }

    public function setLocalId(?int $localId): void
    {
        $this->localId = $localId;
    }

    public function getMac(): ?string
    {
        return $this->mac;
    }

    public function setMac(?string $mac): void
    {
        $this->mac = $this->sanitizeNullableString($mac);
    }

    public function getNumSerie(): ?string
    {
        return $this->numSerie;
    }

    public function setNumSerie(?string $numSerie): void
    {
        $this->numSerie = $this->sanitizeNullableString($numSerie);
    }
}
