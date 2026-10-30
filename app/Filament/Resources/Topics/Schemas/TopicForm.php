<?php

namespace App\Filament\Resources\Topics\Schemas;

use App\Models\Topic;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TopicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name_ar')->label('Name (Arabic)')->required()->maxLength(255)->extraInputAttributes(['dir' => 'auto']),
                TextInput::make('name_en')->label('Name (English)')->maxLength(255),
                TextInput::make('key')
                    ->required()->unique(ignoreRecord: true)->maxLength(255)
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->helperText('Stable identifier used in URLs; it can only be set when the topic is created.'),
                TextInput::make('position')->numeric()->integer()->minValue(0)->default(0)->required(),
                Select::make('parent_id')
                    ->label('Parent topic')
                    ->relationship(
                        'parent',
                        'name_ar',
                        fn (Builder $query, ?Model $record) => $record ? $query->whereNotIn('id', [$record->getKey(), ...self::descendantIds($record)]) : $query,
                    )
                    ->rule(fn (?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                        if ($record && in_array((int) $value, [$record->getKey(), ...self::descendantIds($record)], true)) {
                            $fail('A topic cannot be placed under itself or one of its own sub-topics.');
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->placeholder('None (top level)'),
            ]),
        ])->columns(1);
    }

    /**
     * Every topic below the given one, at any depth.
     *
     * @return array<int, int>
     */
    private static function descendantIds(Model $topic): array
    {
        $found = [];
        $level = [$topic->getKey()];

        while ($level) {
            $level = Topic::query()->whereIn('parent_id', $level)->pluck('id')->diff($found)->all();
            $found = [...$found, ...$level];
        }

        return $found;
    }
}
