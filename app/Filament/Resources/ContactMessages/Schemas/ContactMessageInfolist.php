<?php

namespace App\Filament\Resources\ContactMessages\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('name')->placeholder('Not given')->extraAttributes(['dir' => 'auto']),
                TextEntry::make('email')->copyable(),
                TextEntry::make('locale'),
                TextEntry::make('status')->badge()->color(fn (string $state) => $state === 'new' ? 'danger' : 'success'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('message')->columnSpanFull()->extraAttributes(['dir' => 'auto', 'style' => 'white-space:pre-wrap']),
            ]),
        ])->columns(1);
    }
}
