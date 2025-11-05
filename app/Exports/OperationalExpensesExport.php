<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use App\Models\OperationalExpense;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class OperationalExpensesExport implements FromQuery, WithHeadings, WithMapping, WithColumnWidths, WithTitle
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
                'expenseType',
                'despatch',
                'client'
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

        return $query->orderBy('expense_date', 'desc');
    }

    public function headings(): array
    {
        return [
            'Conductor',
            'Unidad',
            'Fecha',
            'Guía/Doc',
            'Punto 1',
            'Punto de Partida',
            'Punto de Llegada',
            'Punto 4',
            'Peso Bruto',
            'Producto',
            'Peajes',
            'Gastos de Carga',
            'Viáticos',
            'Gastos Operativos',
            'Sueldo Variable',
            'Jefe de Operaciones',
            'Seguridad',
            'Monto Total',
        ];
    }

    public function map($expense): array
    {
        // Calcular gastos operativos
        $peajes = $expense->despatch->tolls ?? 0;
        $gastosCarga = $expense->despatch->loading_expenses ?? 0;
        $viaticos = $expense->despatch->travel_allowances ?? 0;
        $gastosOperativos = $peajes + $gastosCarga + $viaticos;

        return [
            // Conductor
            $expense->driver?->full_name ?? '',

            // Unidad
            $expense->vehicle?->plate_number ?? '',

            // Fecha
            $expense->expense_date?->format('d/m/Y') ?? '',

            // Guía/Doc
            $expense->despatch ?
                $expense->despatch->series . '-' . $expense->despatch->number :
                ($expense->document_number ?? ''),

            // Punto 1 (Carga)
            $expense->despatch?->loading_point ?? '',

            // Punto de Partida
            $expense->despatch?->departure_location ?? '',

            // Punto de Llegada
            $expense->despatch?->arrival_location ?? '',

            // Punto 4 (Descarga)
            $expense->despatch?->unloading_point ?? '',

            // Peso Bruto
            $expense->despatch && $expense->despatch->total_gross_weight ?
                number_format($expense->despatch->total_gross_weight, 2) . ' ' . $expense->despatch->total_gross_weight_unit_of_measure : '',

            // Producto
            $expense->despatch?->product ?? '',

            // Peajes
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->tolls ?? 0, 2) : 'S/. 0.00',

            // Gastos de Carga
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->loading_expenses ?? 0, 2) : 'S/. 0.00',

            // Viáticos
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->travel_allowances ?? 0, 2) : 'S/. 0.00',

            // Gastos Operativos
            'S/. ' . number_format($gastosOperativos, 2),

            // Sueldo Variable
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->variable_salary ?? 0, 2) : 'S/. 0.00',

            // Jefe de Operaciones
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->operations_manager ?? 0, 2) : 'S/. 0.00',

            // Seguridad
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->security ?? 0, 2) : 'S/. 0.00',

            // Monto Total
            'S/. ' . number_format($expense->amount ?? 0, 2),
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20, // Conductor
            'B' => 12, // Unidad
            'C' => 12, // Fecha
            'D' => 15, // Guía/Doc
            'E' => 25, // Punto 1
            'F' => 30, // Punto de Partida
            'G' => 30, // Punto de Llegada
            'H' => 25, // Punto 4
            'I' => 15, // Peso Bruto
            'J' => 20, // Producto
            'K' => 12, // Peajes
            'L' => 15, // Gastos de Carga
            'M' => 12, // Viáticos
            'N' => 18, // Gastos Operativos (NUEVA)
            'O' => 15, // Sueldo Variable
            'P' => 18, // Jefe de Operaciones
            'Q' => 12, // Seguridad
            'R' => 15, // Monto Total
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

        return 'Gastos Operativos' . $dateRange;
    }
}
