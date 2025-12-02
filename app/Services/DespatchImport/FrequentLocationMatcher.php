<?php

namespace App\Services\DespatchImport;

use App\Models\FrequentLocation;
use App\Models\OperationalExpenseConfig;

class FrequentLocationMatcher
{
    /**
     * Infiere el punto de ruta (point) basándose en dirección y opcionalmente ubigeo
     * Usa normalización flexible, extracción de KM y similitud para encontrar coincidencias
     */
    public function inferLocationPoint(?string $address, ?string $ubigeo = null): ?string
    {
        if (!$address) {
            return null;
        }

        // PASO 1: Buscar por ubigeo primero (si está disponible)
        if ($ubigeo) {
            $ubigeoMatch = $this->findByUbigeo($ubigeo, $address);
            if ($ubigeoMatch) {
                return $ubigeoMatch->point;
            }
        }

        // PASO 2: Extraer kilómetro de la dirección
        $kilometer = $this->extractKilometer($address);

        // PASO 3: Normalizar la dirección de entrada
        $normalizedAddress = $this->normalizeForComparison($address);

        // PASO 4: Buscar coincidencia exacta (normalizada)
        $exactMatch = FrequentLocation::where('is_active', true)
            ->get()
            ->first(function ($location) use ($normalizedAddress) {
                return $this->normalizeForComparison($location->name) === $normalizedAddress;
            });

        if ($exactMatch) {
            return $exactMatch->point;
        }

        // PASO 5: Si tenemos kilómetro, buscar por coincidencia de KM + carretera
        if ($kilometer !== null) {
            $kmMatch = $this->findByKilometerAndRoad($address, $kilometer);
            if ($kmMatch) {
                return $kmMatch->point;
            }
        }

        // PASO 6: Buscar por similitud con umbral reducido (70%)
        $threshold = 70; // Reducido de 85% a 70%
        $bestMatch = null;
        $bestScore = 0;

        foreach (FrequentLocation::where('is_active', true)->get() as $location) {
            $normalizedLocationName = $this->normalizeForComparison($location->name);

            // Calcular similitud
            similar_text($normalizedAddress, $normalizedLocationName, $percent);

            // Bonus si coincide el kilómetro
            if ($kilometer !== null) {
                $locationKm = $this->extractKilometer($location->name);
                if ($locationKm !== null && abs($kilometer - $locationKm) <= 1) {
                    // Si el KM coincide (±1km de tolerancia), dar bonus del 20%
                    $percent = min(100, $percent + 20);
                }
            }

            if ($percent >= $threshold && $percent > $bestScore) {
                $bestScore = $percent;
                $bestMatch = $location;
            }
        }

        return $bestMatch?->point;
    }
    /**
     * Busca location por ubigeo
     */
    protected function findByUbigeo(string $ubigeo, string $address): ?FrequentLocation
    {
        // Buscar locations que contengan el ubigeo en su nombre
        // Formato común: "150812 - PANAMERICANA NORTE KM. 170.6 VEGUETA"
        $locations = FrequentLocation::where('is_active', true)
            ->where('name', 'LIKE', $ubigeo . '%')
            ->get();

        if ($locations->count() === 1) {
            return $locations->first();
        }

        // Si hay múltiples con el mismo ubigeo, usar similitud de dirección
        if ($locations->count() > 1) {
            $normalizedAddress = $this->normalizeForComparison($address);
            $bestMatch = null;
            $bestScore = 0;

            foreach ($locations as $location) {
                $normalizedLocationName = $this->normalizeForComparison($location->name);
                similar_text($normalizedAddress, $normalizedLocationName, $percent);

                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $bestMatch = $location;
                }
            }

            return $bestMatch;
        }

        return null;
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

        // Umbral de similitud (70% más flexible)
        $threshold = 70;

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
