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
            'Categoría Gasto',
            'Guía/Doc',
            'Punto de Partida',
            'Punto de Llegada',
            'Punto 1',
            'Punto 4',
            'Peso Bruto',
            'Peso Neto',
            'Venta Neta',
            'Producto',
            'Peajes',
            'Gastos de Carga',
            'Viáticos',
            'Sueldo Variable',
            'Jefe de Operaciones',
            'Seguridad',
            'Proveedor',
            'Monto Total',
            'Descripción',
            'Estado',
        ];
    }

    public function map($expense): array
    {
        return [
            // Conductor
            $expense->driver?->full_name ?? '',

            // Unidad
            $expense->vehicle?->plate_number ?? '',

            // Fecha
            $expense->expense_date?->format('d/m/Y') ?? '',

            // Categoría Gasto
            $expense->expenseType ?
                ($expense->expenseType->category === 'fixed' ? 'Regular' : 'Variable') : '',

            // Guía/Doc
            $expense->despatch ?
                $expense->despatch->series . '-' . $expense->despatch->number :
                ($expense->document_number ?? ''),

            // Punto de Partida
            $expense->despatch?->departure_address ?? '',

            // Punto de Llegada
            $expense->despatch?->arrival_address ?? '',

            // Punto 1 (Carga)
            $expense->despatch?->loading_point ?? '',

            // Punto 4 (Descarga)
            $expense->despatch?->unloading_point ?? '',

            // Peso Bruto
            $expense->despatch && $expense->despatch->total_gross_weight ?
                number_format($expense->despatch->total_gross_weight, 2) . ' ' . $expense->despatch->total_gross_weight_unit_of_measure : '',

            // Peso Neto
            $expense->despatch && $expense->despatch->net_weight ?
                number_format($expense->despatch->net_weight, 2) . ' ' . $expense->despatch->total_gross_weight_unit_of_measure : '',
            
            // Venta Neta
            $expense->despatch && $expense->despatch->net_sale ?
                'S/. ' . number_format($expense->despatch->net_sale, 2) : 'S/. 0.00',

            // Producto
            $expense->despatch?->product ?? '',

            // Peajes
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->tolls ?? 0, 2) : 'S/. 0.00',

            // Gastos de Carga
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->loading_expenses ?? 0, 2) : 'S/. 0.00',

            // Viáticos
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->travel_allowances ?? 0, 2) : 'S/. 0.00',

            // Sueldo Variable
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->variable_salary ?? 0, 2) : 'S/. 0.00',

            // Jefe de Operaciones
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->operations_manager ?? 0, 2) : 'S/. 0.00',

            // Seguridad
            $expense->despatch ? 'S/. ' . number_format($expense->despatch->security ?? 0, 2) : 'S/. 0.00',

            // Proveedor
            $expense->supplier ?? '',

            // Monto Total
            'S/. ' . number_format($expense->amount ?? 0, 2),

            // Descripción
            $expense->description ?? '',

            // Estado
            match($expense->status) {
                'pending' => 'Pendiente',
                'paid' => 'Pagado',
                default => $expense->status ?? ''
            },
        ];
    }    

    public function columnWidths(): array
    {
        return [
            'A' => 20, // Conductor
            'B' => 12, // Unidad
            'C' => 12, // Fecha
            'D' => 15, // Categoría Gasto
            'E' => 15, // Guía/Doc
            'F' => 30, // Punto de Partida
            'G' => 30, // Punto de Llegada
            'H' => 25, // Punto 1
            'I' => 25, // Punto 4
            'J' => 15, // Peso Bruto
            'K' => 15, // Peso Neto
            'L' => 15, // Venta Neta
            'M' => 20, // Producto
            'N' => 12, // Peajes
            'O' => 15, // Gastos de Carga
            'P' => 12, // Viáticos
            'Q' => 15, // Sueldo Variable
            'R' => 18, // Jefe de Operaciones
            'S' => 12, // Seguridad
            'T' => 20, // Proveedor
            'U' => 15, // Monto Total
            'V' => 30, // Descripción
            'W' => 12, // Estado
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
