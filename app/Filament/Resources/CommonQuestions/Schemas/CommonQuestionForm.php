<?php

namespace App\Filament\Resources\CommonQuestions\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CommonQuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->description('A visitor who asks exactly this question (ignoring harakat, punctuation and case) gets the stored answer at once. Rewording a question drops its stored answer until it is regenerated.')
                ->columns(2)
                ->schema([
                    Textarea::make('question_ar')->label('Question (Arabic)')->required()->minLength(3)->maxLength(1000)->rows(2)->extraInputAttributes(['dir' => 'rtl']),
                    Textarea::make('question_en')->label('Question (English)')->minLength(3)->maxLength(1000)->rows(2)->extraInputAttributes(['dir' => 'ltr']),
                    TextInput::make('position')->numeric()->integer()->minValue(0)->required()->default(0),
                    Toggle::make('is_active')->label('Shown on the home page')->default(true)->inline(false),
                ]),
        ])->columns(1);
    }
}
