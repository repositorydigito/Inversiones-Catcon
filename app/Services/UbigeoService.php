<?php

namespace App\Services;

class UbigeoService
{
    protected array $ubigeoData;

    public function __construct()
    {
        $this->ubigeoData = config('ubigeo', []);
    }
    
    public function getDepartamentos(): array
    {
        $departamentos = $this->ubigeoData['departamentos'] ?? [];
        
        $ordered = collect($departamentos)
            ->sortBy(function ($name, $code) {
                // Prioridad especial para LIMA y CALLAO
                if ($name === 'LIMA') return '1'; 
                if ($name === 'CALLAO') return '2'; 
                return '3' . $name; 
            })
            ->toArray();

        return $ordered;
    }

    public function getProvincias(string $departamentoCode): array
    {
        return $this->ubigeoData['provincias'][$departamentoCode] ?? [];
    }

    public function getDistritos(string $departamentoCode, string $provinciaCode): array
    {
        $key = $departamentoCode . $provinciaCode;
        return $this->ubigeoData['distritos'][$key] ?? [];
    }

    public function generateUbigeoCode(string $departamento, string $provincia, string $distrito): string
    {
        return $departamento . $provincia . $distrito;
    }

    public function getUbigeoName(string $ubigeoCode): string
    {
        if (strlen($ubigeoCode) !== 6) {
            return 'Código inválido';
        }

        $depCode = substr($ubigeoCode, 0, 2);
        $provCode = substr($ubigeoCode, 2, 2);
        $distCode = substr($ubigeoCode, 4, 2);

        $departamento = $this->ubigeoData['departamentos'][$depCode] ?? '';
        $provincia = $this->ubigeoData['provincias'][$depCode][$provCode] ?? '';
        $distrito = $this->ubigeoData['distritos'][$depCode.$provCode][$distCode] ?? '';

        return "{$distrito}, {$provincia}, {$departamento}";
    }
}
