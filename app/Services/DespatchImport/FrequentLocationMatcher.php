<?php

namespace App\Services\DespatchImport;

use App\Models\FrequentLocation;
use App\Models\OperationalExpenseConfig;

class FrequentLocationMatcher
{
    /**
     * Infiere el punto de ruta (point) basándose en dirección
     * Usa normalización flexible, extracción de KM y similitud para encontrar coincidencias
     */
    public function inferLocationPoint(?string $address, ?string $ubigeo = null): ?string
    {
        if (!$address) {
            return null;
        }

        // PASO 1: Extraer información relevante de la dirección
        $kilometer = $this->extractKilometer($address);
        $numbers = $this->extractNumbers($address);

        // PASO 2: Normalizar la dirección de entrada
        $normalizedAddress = $this->normalizeForComparison($address);

        // PASO 3: Buscar coincidencia exacta (normalizada)
        $exactMatch = FrequentLocation::where('is_active', true)
            ->get()
            ->first(function ($location) use ($normalizedAddress) {
                return $this->normalizeForComparison($location->name) === $normalizedAddress;
            });

        if ($exactMatch) {
            return $exactMatch->point;
        }

        // PASO 4: Si tenemos kilómetro, buscar por coincidencia de KM + carretera
        if ($kilometer !== null) {
            $kmMatch = $this->findByKilometerAndRoad($address, $kilometer);
            if ($kmMatch) {
                return $kmMatch->point;
            }
        }

        // PASO 5: Buscar por similitud con bonificaciones inteligentes
        $threshold = 70;
        $bestMatch = null;
        $bestScore = 0;

        foreach (FrequentLocation::where('is_active', true)->get() as $location) {
            $normalizedLocationName = $this->normalizeForComparison($location->name);

            // Calcular similitud base
            similar_text($normalizedAddress, $normalizedLocationName, $percent);

            // BONUS 1: Si coincide el kilómetro (±1km de tolerancia)
            if ($kilometer !== null) {
                $locationKm = $this->extractKilometer($location->name);
                if ($locationKm !== null && abs($kilometer - $locationKm) <= 1) {
                    $percent = min(100, $percent + 20);
                }
            }

            // BONUS 2: Por números coincidentes (ubigeos, números de calle, etc.)
            $locationNumbers = $this->extractNumbers($location->name);
            $matchingNumbers = array_intersect($numbers, $locationNumbers);
            if (count($matchingNumbers) > 0) {
                // +5% por cada número que coincida (máximo +15%)
                $numberBonus = min(15, count($matchingNumbers) * 5);
                $percent = min(100, $percent + $numberBonus);
            }

            if ($percent >= $threshold && $percent > $bestScore) {
                $bestScore = $percent;
                $bestMatch = $location;
            }
        }

        return $bestMatch?->point;
    }
    /**
     * Extrae el número de kilómetro de una dirección
     * Ejemplos:
     * - "CAR. PANAMERICANA NORTE KM 130" -> 130
     * - "PANAMERICANA NORTE KM. 170.6" -> 170.6
     * - "KM200" -> 200
     */
    protected function extractKilometer(string $address): ?float
    {
        // Patrón para buscar KM seguido de número (con o sin punto decimal)
        // Soporta: KM 130, KM. 130, KM130, KM.130, KM 170.6, etc.
        if (preg_match('/\bkm\.?\s*(\d+(?:\.\d+)?)/i', $address, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }
    /**
     * Extrae todos los números relevantes de una dirección
     * (ubigeos, números de calle, etc.)
     * Ejemplos:
     * - "070101 - AV. OQUENDO NRO. 9201" -> [070101, 9201]
     * - "AV. N STOR GAMBETTA 8583" -> [8583]
     * - "150812 - PANAMERICANA NORTE KM. 170.6" -> [150812, 170]
     */
    protected function extractNumbers(string $address): array
    {
        $numbers = [];

        // Buscar todos los números de 3 o más dígitos
        // Esto captura: ubigeos (6 dígitos), números de calle, kilómetros sin KM, etc.
        if (preg_match_all('/\b(\d{3,})\b/', $address, $matches)) {
            foreach ($matches[1] as $number) {
                // Convertir a entero para normalizar (elimina ceros a la izquierda)
                $numbers[] = (int) $number;
            }
        }

        return array_unique($numbers);
    }
    /**
     * Busca por coincidencia de kilómetro y nombre de carretera
     */
    protected function findByKilometerAndRoad(string $address, float $kilometer): ?FrequentLocation
    {
        // Extraer palabras clave de la carretera (ej: "PANAMERICANA NORTE")
        $roadKeywords = $this->extractRoadKeywords($address);

        if (empty($roadKeywords)) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach (FrequentLocation::where('is_active', true)->get() as $location) {
            $locationKm = $this->extractKilometer($location->name);

            // Si no tiene KM, skip
            if ($locationKm === null) {
                continue;
            }

            // Verificar que el KM sea similar (±5km de tolerancia)
            if (abs($kilometer - $locationKm) > 5) {
                continue;
            }

            // Verificar que la carretera coincida
            $locationRoadKeywords = $this->extractRoadKeywords($location->name);
            $matchingKeywords = array_intersect($roadKeywords, $locationRoadKeywords);

            // Calcular score basado en palabras clave coincidentes
            $score = (count($matchingKeywords) / max(count($roadKeywords), 1)) * 100;

            // Bonus por proximidad de kilómetro
            $kmDiff = abs($kilometer - $locationKm);
            if ($kmDiff <= 1) {
                $score += 20; // Muy cercano
            } elseif ($kmDiff <= 3) {
                $score += 10; // Cercano
            }

            if ($score > $bestScore && $score >= 60) {
                $bestScore = $score;
                $bestMatch = $location;
            }
        }

        return $bestMatch;
    }
    /**
     * Extrae palabras clave de la carretera
     * Ejemplo: "CAR. PANAMERICANA NORTE KM 130" -> ["panamericana", "norte"]
     */
    protected function extractRoadKeywords(string $address): array
    {
        $normalized = $this->normalizeForComparison($address);

        // Palabras clave a ignorar
        $stopWords = ['car', 'carr', 'carretera', 'av', 'avenida', 'calle', 'jr', 'jirón',
                      'pje', 'pasaje', 'km', 'zona', 'z', 'industrial', 'altura', 'alt',
                      'referencia', 'ref', 'esq', 'esquina', 'cdra', 'cuadra', 'sn',
                      'mz', 'manzana', 'lt', 'lote', 'int', 'interior', 'nro', 'numero'];

        // Dividir en palabras
        $words = explode(' ', $normalized);

        // Filtrar palabras clave
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) >= 3 && !in_array($word, $stopWords) && !is_numeric($word);
        });

        return array_values($keywords);
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

        // Umbral de similitud (65% más flexible)
        $threshold = 65;

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
