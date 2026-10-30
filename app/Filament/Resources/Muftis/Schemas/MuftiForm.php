<?php

namespace App\Filament\Resources\Muftis\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MuftiForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name_ar')->label('Name (Arabic)')->disabled()->extraInputAttributes(['dir' => 'auto']),
                TextInput::make('name_en')->label('Name (English)')->maxLength(255),
                TextInput::make('key')->disabled(),
                TextInput::make('kind')->disabled(),
            ]),
            Section::make('Bio')->columns(2)->schema([
                Textarea::make('bio_ar')->label('Bio (Arabic)')->rows(10)->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('bio_en')->label('Bio (English)')->rows(10)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('source_url')->label('Source URL')->url()->columnSpanFull(),
            ]),
        ])->columns(1);
    }
}
