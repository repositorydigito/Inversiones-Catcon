<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use SolutionForest\FilamentSimpleLightBox\SimpleLightBoxPlugin;
use Solutionforest\FilamentLoginScreen\Filament\Pages\Auth\Themes\Theme1\LoginScreenPage;
use Filament\Support\Enums\MaxWidth;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use TomatoPHP\FilamentUsers\FilamentUsersPlugin;
use Joaopaulolndev\FilamentEditProfile\FilamentEditProfilePlugin;
use Joaopaulolndev\FilamentEditProfile\Pages\EditProfilePage;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Catcon')
            ->brandLogo(asset('images/logocatcon.png'))
            ->darkMode(false)
            ->sidebarFullyCollapsibleOnDesktop()
            ->colors([
                'primary' => '#93160A',
                'gray' => Color::Slate,
                'info' => Color::Cyan,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->font('Inter')
            ->maxContentWidth(MaxWidth::ScreenTwoExtraLarge)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Perfil')
                    ->url(fn (): string => EditProfilePage::getUrl())
                    ->icon('heroicon-o-user')
            ])
            ->sidebarWidth('18rem')
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k']) 
            ->navigationGroups([                
                NavigationGroup::make()
                    ->label('Entidades')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Filament Shield')
                    ->collapsible(true),
            ])           
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
                FilamentUsersPlugin::make(),
                FilamentEditProfilePlugin::make()->shouldRegisterNavigation(false),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications();
    }
}
