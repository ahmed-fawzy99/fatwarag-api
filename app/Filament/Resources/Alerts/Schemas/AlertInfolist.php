<?php

namespace App\Filament\Resources\Alerts\Schemas;

use App\Filament\Support\Text;
use App\Models\Alert;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('kind')->badge(),
                TextEntry::make('occurrences')->numeric(),
                TextEntry::make('first_seen_at')->dateTime(),
                TextEntry::make('last_seen_at')->dateTime(),
                TextEntry::make('resolved_at')->dateTime()->placeholder('Still open'),
                TextEntry::make('notified_at')->dateTime()->placeholder('Not sent'),
                TextEntry::make('message')->columnSpanFull()->extraAttributes(['dir' => 'auto']),
            ]),
            Section::make('Context')->schema([
                TextEntry::make('context')
                    ->hiddenLabel()
                    ->state(fn (Alert $record) => Text::definitionList($record->context))
                    ->html(),
            ]),
        ])->columns(1);
    }
}
