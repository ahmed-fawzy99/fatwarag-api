<?php

namespace App\Filament\Resources\Fatwas\Schemas;

use App\Filament\Support\Text;
use App\Models\Fatwa;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FatwaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('title')->columnSpanFull()->extraAttributes(['dir' => 'auto']),
                TextEntry::make('public_id')->copyable(),
                TextEntry::make('source.name_en')->label('Source'),
                TextEntry::make('mufti.name_ar')->label('Mufti')->placeholder('-')->extraAttributes(['dir' => 'auto']),
                TextEntry::make('url')->url(fn (Fatwa $record) => $record->url, shouldOpenInNewTab: true)->limit(40),
                TextEntry::make('date_gregorian')->label('Date')->date()->placeholder('-'),
                TextEntry::make('hijri')->state(fn (Fatwa $record) => $record->hijri_year
                    ? implode('/', array_filter([$record->hijri_year, $record->hijri_month, $record->hijri_day]))
                    : null)->placeholder('-'),
                TextEntry::make('is_cross_reference')->label('Cross-reference only')->badge()->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
                TextEntry::make('collected_at')->dateTime()->placeholder('-'),
                TextEntry::make('origin')->columnSpanFull()->placeholder('-')->extraAttributes(['dir' => 'auto']),
            ]),
            Section::make('Question')->collapsible()->schema([
                TextEntry::make('question')->hiddenLabel()->state(fn (Fatwa $record) => Text::paragraphs($record->question))->html(),
            ]),
            Section::make('Answer')->collapsible()->schema([
                TextEntry::make('answer')->hiddenLabel()->state(fn (Fatwa $record) => Text::paragraphs($record->answer))->html(),
            ]),
        ])->columns(1);
    }
}
