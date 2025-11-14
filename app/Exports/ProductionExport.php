<?php

namespace App\Exports;

use App\Models\OperationalExpense;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProductionExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithTitle, WithColumnFormatting
{
    protected $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $query = OperationalExpense::query()
            ->with([
                'driver',
                'vehicle',
                'despatch',
                'expenseType',
            ]);

        // Aplicar filtros
        if (!empty($this->filters['driver_id'])) {
            $query->where('driver_id', $this->filters['driver_id']);
        }

        if (!empty($this->filters['vehicle_id'])) {
            $query->where('vehicle_id', $this->filters['vehicle_id']);
        }

        if (!empty($this->filters['date_from'])) {
            $query->whereDate('expense_date', '>=', $this->filters['date_from']);
        }

        if (!empty($this->filters['date_to'])) {
            $query->whereDate('expense_date', '<=', $this->filters['date_to']);
        }

        // Incluir solo gastos fijos
        $query->whereHas('expenseType', function ($q) {
            $q->where('category', 'fixed');
        });

        return $query->orderBy('expense_date', 'asc');
    }

    public function headings(): array
    {
        return [
            'Conductor',
            'Fecha',
            'Guía',
            'Producto',
            'Peso Bruto (KGM)',
            'Punto 1',
            'Punto Partida',
            'Punto Descarga',
            'Punto 4',
            'Valor Unitario (S/.)',
            'Venta Neta (S/.)',
            'Venta Bruta (S/.)',
        ];
    }

    public function map($expense): array
    {
        return [
            // Conductor
            $expense->driver?->full_name ?? '',

            // Fecha
            $expense->expense_date?->format('d/m/Y') ?? '',

            // Guía
            $expense->despatch ?
                $expense->despatch->series . '-' . $expense->despatch->number : '',

            // Producto (para gastos variables se muestra la descripción)
            ($expense->expenseType?->category === 'variable')
                ? ($expense->description ?? '')
                : ($expense->despatch?->product ?? ''),

            // Peso Bruto - SOLO NÚMERO
            round((float)($expense->despatch?->total_gross_weight ?? 0), 2),

            // Punto 1
            $expense->despatch?->loading_point ?? '',

            // Punto Partida
            $expense->despatch?->departure_location ?? '',

            // Punto Descarga
            $expense->despatch?->arrival_location ?? '',

            // Punto 4
            $expense->despatch?->unloading_point ?? '',

            // Valor Unitario - SOLO NÚMERO (3 decimales)
            round((float)($expense->despatch?->rate ?? 0), 3),

            // Venta Neta - SOLO NÚMERO
            round((float)($expense->despatch?->net_sale ?? 0), 2),

            // Venta Bruta - SOLO NÚMERO
            round((float)($expense->despatch?->gross_sale ?? 0), 2),

        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20, // Conductor
            'B' => 12, // Fecha
            'C' => 15, // Guía
            'D' => 20, // Producto
            'E' => 15, // Peso
            'F' => 25, // Punto 1
            'G' => 30, // Punto Partida
            'H' => 30, // Punto Descarga
            'I' => 25, // Punto 4
            'J' => 15, // Valor Unitario
            'K' => 15, // Venta Neta
            'L' => 15, // Venta Bruta
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_NUMBER_00, // Peso Bruto
            'J' => '0.000', // Valor Unitario (3 decimales)
            'K' => NumberFormat::FORMAT_NUMBER_00, // Venta Neta
            'L' => NumberFormat::FORMAT_NUMBER_00, // Venta Bruta
        ];
    }

    public function title(): string
    {
        $dateRange = '';
        if (!empty($this->filters['date_from']) || !empty($this->filters['date_to'])) {
            $from = !empty($this->filters['date_from']) ? \Carbon\Carbon::parse($this->filters['date_from'])->format('d-m-Y') : '';
            $to = !empty($this->filters['date_to']) ? \Carbon\Carbon::parse($this->filters['date_to'])->format('d-m-Y') : '';

            if ($from && $to) {
                $dateRange = " ({$from} al {$to})";
            } elseif ($from) {
                $dateRange = " (desde {$from})";
            } elseif ($to) {
                $dateRange = " (hasta {$to})";
            }
        }

        return 'Producción' . $dateRange;
    }
}
