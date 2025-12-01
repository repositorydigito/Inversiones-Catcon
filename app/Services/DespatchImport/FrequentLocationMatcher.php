<?php

namespace App\Services\DespatchImport;

use App\Models\FrequentLocation;
use App\Models\OperationalExpenseConfig;

class FrequentLocationMatcher
{
    /**
     * Infiere el punto de ruta (point) basándose en una dirección
     * Usa normalización flexible y similitud para encontrar coincidencias
     */
    public function inferLocationPoint(?string $address): ?string
    {
        if (!$address) {
            return null;
        }

        // Normalizar la dirección de entrada
        $normalizedAddress = $this->normalizeForComparison($address);

        // Buscar coincidencia exacta primero (normalizada)
        $exactMatch = FrequentLocation::where('is_active', true)
            ->get()
            ->first(function ($location) use ($normalizedAddress) {
                return $this->normalizeForComparison($location->name) === $normalizedAddress;
            });

        if ($exactMatch) {
            return $exactMatch->point;
        }

        // Si no hay coincidencia exacta, buscar por similitud (umbral 85%)
        $threshold = 85;
        $bestMatch = null;
        $bestScore = 0;

        foreach (FrequentLocation::where('is_active', true)->get() as $location) {
            $normalizedLocationName = $this->normalizeForComparison($location->name);

            similar_text($normalizedAddress, $normalizedLocationName, $percent);

            if ($percent >= $threshold && $percent > $bestScore) {
                $bestScore = $percent;
                $bestMatch = $location;
            }
        }

        return $bestMatch?->point;
    }
    /**
     * Busca configuración con normalización flexible y similitud
     */
    public function findOperationalConfig($loadingPoint, $departureLocation, $arrivalLocation, $unloadingPoint)
    {
        if (!$loadingPoint || !$departureLocation || !$arrivalLocation || !$unloadingPoint) {
            return null;
        }

        // Normalizar los valores de entrada
        $normalizedInput = [
            'loading_point' => $this->normalizeForComparison($loadingPoint),
            'departure_location' => $this->normalizeForComparison($departureLocation),
            'arrival_location' => $this->normalizeForComparison($arrivalLocation),
            'unloading_point' => $this->normalizeForComparison($unloadingPoint),
        ];

        // Umbral de similitud (85% = bastante flexible, 90% = más estricto)
        $threshold = 85;

        // Buscar coincidencia exacta primero
        $exactMatch = OperationalExpenseConfig::all()
            ->first(function ($config) use ($normalizedInput) {
                return $this->normalizeForComparison($config->departure_point) === $normalizedInput['loading_point']
                    && $this->normalizeForComparison($config->departure_location) === $normalizedInput['departure_location']
                    && $this->normalizeForComparison($config->arrival_location) === $normalizedInput['arrival_location']
                    && $this->normalizeForComparison($config->destination_point) === $normalizedInput['unloading_point'];
            });

        if ($exactMatch) {
            return $exactMatch;
        }

        // Si no hay coincidencia exacta, buscar por similitud
        $bestMatch = null;
        $bestScore = 0;

        foreach (OperationalExpenseConfig::all() as $config) {
            $score = $this->calculateSimilarityScore($config, $normalizedInput);

            if ($score >= $threshold && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $config;
            }
        }

        return $bestMatch;
    }
    /**
     * Calcula un score de similitud entre la configuración y los valores de entrada
     * Retorna un porcentaje de 0 a 100
     */
    protected function calculateSimilarityScore($config, array $normalizedInput): float
    {
        $scores = [];

        // Comparar cada campo
        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->departure_point),
            $normalizedInput['loading_point']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->departure_location),
            $normalizedInput['departure_location']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->arrival_location),
            $normalizedInput['arrival_location']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->destination_point),
            $normalizedInput['unloading_point']
        );

        // Retornar el promedio de similitud de todos los campos
        return array_sum($scores) / count($scores);
    }
    /**
     * Calcula similitud entre dos strings (0-100)
     */
    protected function stringSimilarity(string $str1, string $str2): float
    {
        // Si son exactamente iguales, 100%
        if ($str1 === $str2) {
            return 100;
        }

        // Si alguno está vacío, 0%
        if (empty($str1) || empty($str2)) {
            return 0;
        }

        // Usar similar_text que es más rápido que levenshtein
        similar_text($str1, $str2, $percent);

        return $percent;
    }
    /**
     * Normaliza una cadena para comparación flexible
     * Elimina puntos, comas, guiones, espacios extras y convierte a minúsculas
     */
    protected function normalizeForComparison(string $text): string
    {
        // Convertir a minúsculas
        $text = strtolower($text);

        // Eliminar palabras comunes que no aportan (como S/N, REF, KM, etc.)
        $commonWords = ['s/n', 'ref:', 'referencia:', 'km', 'km.', 'alt', 'altura'];
        foreach ($commonWords as $word) {
            $text = str_replace($word, '', $text);
        }

        // Eliminar puntos, comas, guiones y caracteres especiales
        $text = preg_replace('/[.,\-()\/]/', ' ', $text);

        // Eliminar tildes/acentos
        $text = $this->removeAccents($text);

        // Reemplazar múltiples espacios por uno solo
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim final
        return trim($text);
    }
    /**
     * Elimina tildes y acentos de una cadena
     */
    protected function removeAccents(string $text): string
    {
        $unwanted = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n'
        ];

        return strtr($text, $unwanted);
    }
}
