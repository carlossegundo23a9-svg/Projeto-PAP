<?php
declare(strict_types=1);

require_once __DIR__ . '/maquina.php';
require_once __DIR__ . '/periferico.php';
require_once __DIR__ . '/extra.php';

function material_strip_accents(string $value): string
{
    return str_replace(
        ['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'],
        ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'],
        $value
    );
}

function material_normalize_tipo(string $tipoRaw): ?string
{
    $tipo = strtolower(trim($tipoRaw));
    $tipo = material_strip_accents($tipo);

    if ($tipo === 'maquina' || $tipo === 'maq') {
        return 'maquina';
    }

    if ($tipo === 'periferico' || $tipo === 'perifericos' || $tipo === 'periferico(s)' || $tipo === 'perif') {
        return 'periferico';
    }

    if ($tipo === 'extra' || $tipo === 'extras') {
        return 'extra';
    }

    return null;
}

function material_normalize_estado(string $estadoRaw): string
{
    $estado = strtolower(trim($estadoRaw));
    $estado = material_strip_accents($estado);

    if (in_array($estado, ['2', 'abate', 'abatido', 'desativado', 'desactivado', 'decomissionado', 'descontinuado', 'inativo'], true)) {
        return 'abate';
    }

    if (in_array($estado, ['1', 'sim', 'avariado', 'quebrado', 'broken', 'manutencao'], true)) {
        return 'avariado';
    }

    return 'em_uso';
}

function material_is_broken_from_estado(string $estadoRaw): bool
{
    return material_normalize_estado($estadoRaw) === 'avariado';
}

function material_is_abate_from_estado(string $estadoRaw): bool
{
    return material_normalize_estado($estadoRaw) === 'abate';
}

function material_is_disponivel_from_input(string $disponibilidadeRaw): bool
{
    $value = strtolower(trim($disponibilidadeRaw));
    $value = material_strip_accents($value);

    return !in_array($value, ['0', 'nao', 'não', 'indisponivel', 'indisponível', 'false', 'emprestado', 'ocupado'], true);
}

function material_clean_date(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt && $dt->format('Y-m-d') === $value) {
        return $value;
    }

    return null;
}

function material_build_instance(
    string $tipo,
    ?int $id,
    string $nome,
    ?string $marca,
    ?string $modelo,
    ?string $dataCompra,
    int $localId,
    bool $isBroken,
    bool $isAbate,
    ?string $dataBroken,
    ?string $mac = null,
    ?string $sn = null,
    ?string $descricao = null,
    bool $disponivel = true,
    ?string $codigoInventario = null
): Material {
    if ($tipo === 'maquina') {
        return new Maquina(
            $id,
            $nome,
            $marca,
            $modelo,
            $dataCompra,
            $localId,
            $mac,
            $sn,
            $isBroken,
            $isAbate,
            $dataBroken,
            $disponivel,
            $codigoInventario
        );
    }

    if ($tipo === 'periferico') {
        return new Periferico(
            $id,
            $nome,
            $marca,
            $modelo,
            $dataCompra,
            $localId,
            $isBroken,
            $isAbate,
            $dataBroken,
            $disponivel,
            $codigoInventario
        );
    }

    if ($tipo === 'extra') {
        return new Extra(
            $id,
            $nome,
            $marca,
            $modelo,
            $dataCompra,
            $localId,
            $descricao,
            $isBroken,
            $isAbate,
            $dataBroken,
            $disponivel,
            $codigoInventario
        );
    }

    throw new InvalidArgumentException('Tipo de material inválido.');
}
