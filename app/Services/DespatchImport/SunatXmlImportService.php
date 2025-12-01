<?php

namespace App\Services\DespatchImport;

use App\Models\Despatch;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SunatXmlImportService
{
    protected FrequentLocationMatcher $matcher;
    protected SunatXmlParser $parser;
    protected DespatchEntityManager $entityManager;
    protected DespatchFactory $factory;
    protected DespatchCalculator $calculator;

    public function __construct(
        FrequentLocationMatcher $matcher,
        SunatXmlParser $parser,
        DespatchEntityManager $entityManager,
        DespatchFactory $factory,
        DespatchCalculator $calculator
    ) {
        $this->matcher = $matcher;
        $this->parser = $parser;
        $this->entityManager = $entityManager;
        $this->factory = $factory;
        $this->calculator = $calculator;
    }
    public function importMultipleFromXml(array $xmlFiles): array
    {
        set_time_limit(300);

        $results = [
            'success' => true,
            'total' => count($xmlFiles),
            'imported' => 0,
            'failed' => 0,
            'details' => [],
            'created_entities' => []
        ];

        foreach ($xmlFiles as $index => $xmlContent) {
            try {
                Log::info("Procesando XML " . ($index + 1) . " de " . count($xmlFiles));

                $result = $this->importFromXml($xmlContent);

                if ($result['success']) {
                    $results['imported']++;
                    $results['details'][] = [
                        'status' => 'success',
                        'serie_numero' => $result['despatch']->series . '-' . $result['despatch']->number,
                        'message' => 'Importado exitosamente'
                    ];

                    if (!empty($result['created_entities'])) {
                        $results['created_entities'] = array_merge(
                            $results['created_entities'],
                            $result['created_entities']
                        );
                    }
                } else {
                    $results['failed']++;
                    $results['details'][] = [
                        'status' => 'error',
                        'message' => $result['error']
                    ];
                }

            } catch (Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'status' => 'error',
                    'message' => 'Error: ' . $e->getMessage()
                ];
            }
        }

        // Recalcular viáticos después de importar todas las guías
        if ($results['imported'] > 0) {
            $this->calculator->recalculateTravelAllowancesAfterImport();
        }

        $results['created_entities'] = array_unique($results['created_entities']);
        $results['success'] = $results['imported'] > 0;

        return $results;
    }
    /**
     * Importa una guía de remisión desde XML de SUNAT
     */
    public function importFromXml(string $xmlContent): array
    {
        DB::beginTransaction();

        try {
            Log::info("Iniciando importación de XML de SUNAT");

            // 1. Parsear y validar XML
            $xmlData = $this->parser->parseXmlData($xmlContent);

            // 2. Validar duplicados
            $this->validateDuplicate($xmlData);

            // 3. Procesar entidades relacionadas
            $entities = $this->entityManager->processRelatedEntities($xmlData);

            // 4. Crear el Despatch
            $despatch = $this->factory->createDespatch($xmlData, $entities);

            DB::commit();

            Log::info("XML importado exitosamente", [
                'despatch_id' => $despatch->id,
                'serie_numero' => $despatch->series . '-' . $despatch->number
            ]);

            return [
                'success' => true,
                'despatch' => $despatch,
                'message' => 'Guía importada exitosamente',
                'created_entities' => $entities['created_entities'] ?? []
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::error("Error al importar XML", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Error al importar la guía desde XML'
            ];
        }
    }
    /**
     * Valida que no sea duplicado
     */
    protected function validateDuplicate(array $xmlData): void
    {
        $exists = Despatch::where('series', $xmlData['series'])
                          ->where('number', $xmlData['number'])
                          ->where('company_id', 1) // Tu empresa
                          ->exists();

        if ($exists) {
            throw new Exception("Ya existe una guía con serie {$xmlData['series']} y número {$xmlData['number']}");
        }
    }
}
