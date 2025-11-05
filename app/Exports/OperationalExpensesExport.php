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
            'Peso Bruto (KGM)',
            'Producto',
            'Peajes (S/.)',
            'Gastos de Carga (S/.)',
            'Viáticos (S/.)',
            'Gastos Operativos (S/.)',
            'Sueldo Variable (S/.)',
            'Jefe de Operaciones (S/.)',
            'Seguridad (S/.)',
            'Monto Total (S/.)',
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
            $expense->despatch?->total_gross_weight ?? '',

            // Producto
            $expense->despatch?->product ?? '',

            // Peajes - SOLO NÚMERO
            $peajes,

            // Gastos de Carga - SOLO NÚMERO
            $gastosCarga,

            // Viáticos - SOLO NÚMERO
            $viaticos,

            // Gastos Operativos - SOLO NÚMERO
            $gastosOperativos,

            // Sueldo Variable - SOLO NÚMERO
            $expense->despatch->variable_salary ?? 0,

            // Jefe de Operaciones - SOLO NÚMERO
            $expense->despatch->operations_manager ?? 0,

            // Seguridad - SOLO NÚMERO
            $expense->despatch->security ?? 0,

            // Monto Total - SOLO NÚMERO
            $expense->amount ?? 0,
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
