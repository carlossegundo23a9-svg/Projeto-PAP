<?php
declare(strict_types=1);

abstract class Material
{
    protected ?int $id;
    protected string $nome;
    protected ?string $marca;
    protected ?string $modelo;
    protected ?string $dataCompra;
    protected bool $isBroken;
    protected bool $isAbate;
    protected ?string $dataBroken;
    protected bool $disponivel;
    protected ?string $codigoInventario;
    protected ?string $localNome = null;

    public function __construct(
        ?int $id,
        string $nome,
        ?string $marca,
        ?string $modelo,
        ?string $dataCompra,
        bool $isBroken = false,
        bool $isAbate = false,
        ?string $dataBroken = null,
        bool $disponivel = true,
        ?string $codigoInventario = null
    ) {
        $this->id = $id;
        $this->nome = trim($nome);
        $this->marca = $this->sanitizeNullableString($marca);
        $this->modelo = $this->sanitizeNullableString($modelo);
        $this->dataCompra = $this->sanitizeNullableString($dataCompra);
        $this->isBroken = $isBroken;
        $this->isAbate = $isAbate;
        $this->dataBroken = $this->sanitizeNullableString($dataBroken);
        $this->disponivel = $disponivel;
        $this->codigoInventario = $this->sanitizeNullableString($codigoInventario);
    }

    abstract public function getTipo(): string;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getCodigo(): string
    {
        if ($this->id === null) {
            return 'PENDENTE';
        }

        return 'MAT-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): void
    {
        $this->nome = trim($nome);
    }

    public function getMarca(): ?string
    {
        return $this->marca;
    }

    public function setMarca(?string $marca): void
    {
        $this->marca = $this->sanitizeNullableString($marca);
    }

    public function getModelo(): ?string
    {
        return $this->modelo;
    }

    public function setModelo(?string $modelo): void
    {
        $this->modelo = $this->sanitizeNullableString($modelo);
    }

    public function getDataCompra(): ?string
    {
        return $this->dataCompra;
    }

    public function setDataCompra(?string $dataCompra): void
    {
        $this->dataCompra = $this->sanitizeNullableString($dataCompra);
    }

    public function isBroken(): bool
    {
        return $this->isBroken;
    }

    public function setBroken(bool $isBroken): void
    {
        $this->isBroken = $isBroken;
    }

    public function isAbate(): bool
    {
        return $this->isAbate;
    }

    public function setAbate(bool $isAbate): void
    {
        $this->isAbate = $isAbate;
    }

    public function getDataBroken(): ?string
    {
        return $this->dataBroken;
    }

    public function setDataBroken(?string $dataBroken): void
    {
        $this->dataBroken = $this->sanitizeNullableString($dataBroken);
    }

    public function getEstadoLabel(): string
    {
        if ($this->isAbate) {
            return 'Abate';
        }

        if ($this->isBroken) {
            return 'Avariado';
        }

        return 'Em uso';
    }

    public function isDisponivel(): bool
    {
        return $this->disponivel;
    }

    public function setDisponivel(bool $disponivel): void
    {
        $this->disponivel = $disponivel;
    }

    public function getDisponibilidadeLabel(): string
    {
        return $this->disponivel ? 'Disponível' : 'Indisponível';
    }

    public function getCodigoInventario(): ?string
    {
        return $this->codigoInventario;
    }

    public function setCodigoInventario(?string $codigoInventario): void
    {
        $this->codigoInventario = $this->sanitizeNullableString($codigoInventario);
    }

    public function getLocalNome(): ?string
    {
        return $this->localNome;
    }

    public function setLocalNome(?string $localNome): void
    {
        $this->localNome = $this->sanitizeNullableString($localNome);
    }

    protected function sanitizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
