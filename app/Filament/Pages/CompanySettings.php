<?php

namespace App\Filament\Pages;

use App\Models\Company;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Notifications\Notification; 
use Illuminate\Support\Facades\Hash; 

class CompanySettings extends Page
{
    protected static string $view = 'filament.pages.company-settings';
    protected static ?string $title = 'Empresa';
    protected static bool $shouldRegisterNavigation = false;

    public static function getSlug(): string
    {
        return 'company-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {        
        $company = Company::firstOrCreate([]);        
        $this->form->fill($company->attributesToArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos de la Empresa')
                    ->schema([
                        TextInput::make('ruc')
                            ->label('RUC')
                            ->required(),
                        TextInput::make('name')
                            ->label('Nombre (Razón Social)')
                            ->required(),
                        TextInput::make('commercial_name')
                            ->label('Nombre Comercial')
                            ->nullable(),                        
                    ])->columns(3), 

                Section::make('Entorno del Sistema')
                    ->description('Configuración del entorno para la comunicación con SUNAT/OSE.')
                    ->schema([
                        Select::make('soap_type')
                            ->label('SOAP Tipo')
                            ->options([
                                'demo' => 'Demo',
                                'production' => 'Producción',
                            ])
                            ->required()
                            ->default('demo'),
                        Select::make('soap_delivery_method')
                            ->label('SOAP Envío')
                            ->options([
                                'sunat' => 'SUNAT',
                                'ose' => 'OSE',
                            ])
                            ->required()
                            ->default('sunat'),
                    ])->columns(2),

                Section::make('Consulta CPE')
                    ->description('Credenciales para la consulta de Comprobantes de Pago Electrónicos.')
                    ->schema([
                        TextInput::make('cpe_client_id')
                            ->label('Client ID')
                            ->nullable()
                            ->maxLength(255),
                        TextInput::make('cpe_client_secret')
                            ->label('Client Secret (Clave)')
                            ->nullable(),                            
                    ])->columns(2),

                Section::make('Guías Electrónicas')
                    ->description('Credenciales para la emisión de Guías de Remisión Electrónicas.')
                    ->schema([
                        TextInput::make('electronic_guides_soap_username')
                            ->label('SOAP Usuario')
                            ->nullable()                           
                            ->helperText('RUC + Usuario. Ejemplo: 20123456789DELUSUARIO'),
                        TextInput::make('electronic_guides_soap_password')
                            ->label('SOAP Password')                            
                            ->nullable(),                            
                        TextInput::make('electronic_guides_client_id')
                            ->label('Client ID')
                            ->nullable(),
                        TextInput::make('electronic_guides_client_secret')
                            ->label('Client Secret (Clave)')                            
                            ->nullable(),                            
                    ])->columns(2),

                Section::make('Certificado')
                    ->description('Adjunta tu certificado digital (.p12 o .pfx).')
                    ->schema([
                        FileUpload::make('certificate_path')
                            ->label('Certificado Digital')
                            ->disk('public') // O el disco que uses
                            ->directory('certificates') 
                            ->acceptedFileTypes(['application/x-pkcs12', '.p12', '.pfx']) 
                            ->maxSize(5120) 
                            ->nullable(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            // Valida los datos del formulario.
            $data = $this->form->getState();

            // Obtiene el registro existente o crea uno nuevo.
            $company = Company::firstOrCreate([]);

            // Asegúrate de que las contraseñas/secretos no se sobrescriban con null si no se modifican.
            // Filament's dehydrated() handles this, but a fallback is good practice.
            foreach (['cpe_client_secret', 'electronic_guides_soap_password', 'electronic_guides_client_secret'] as $secretField) {
                if (empty($data[$secretField]) && $company->$secretField) {
                    unset($data[$secretField]); // No actualiza el campo si el input está vacío y ya existe un valor
                }
            }

            // Actualiza el registro con los nuevos datos.
            $company->update($data);

            Notification::make()
                ->title('Configuración de empresa guardada con éxito.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error al guardar la configuración de empresa.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Guardar cambios')
                ->submit('save'), 
        ];
    }

}
