<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Forms;

use Arcturus\ModpackManager\Services\FilePolicyCatalog;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\HtmlString;

final class PolicyFields
{
    public static function rules(bool $new = false): array
    {
        return [
            Select::make('policy_template')->label('Start from a template')->options(ProtectionPolicy::templates())
                ->default($new ? 'arcturus' : null)->placeholder('Keep current rules')->dehydrated(false)->live()
                ->helperText('Selecting a template replaces the unsaved rule list below. Every rule remains editable.')
                ->afterStateUpdated(function ($state, $set): void {
                    if ($state) {
                        $set('file_rules', (new ProtectionPolicy)->template($state));
                    }
                }),
            TagsInput::make('disabled_mods')->label('Disabled mod IDs')->helperText('Stable metadata IDs. Existing disabled/deleted mods also remain disabled across renamed releases.'),
            Repeater::make('file_rules')->label('Advanced rules')->default($new ? (new ProtectionPolicy)->template('arcturus') : [])->columns(3)->cloneable()->reorderableWithButtons()->collapsible()->collapsed()
                ->itemLabel(fn (array $state) => ($state['pattern'] ?? 'New rule').' · '.(ProtectionPolicy::ACTIONS[$state['mode'] ?? ''] ?? 'Choose action'))
                ->helperText('Exact path > mod ID > mod filename > directory (deepest first) > glob > loader > extension. Then priority, then later row. Test rule shows matching files and overlaps.')
                ->schema([
                    Select::make('scope')->label('Match by')->options(ProtectionPolicy::SCOPES)->default('glob')->required(),
                    TextInput::make('pattern')->label('Path / selector')->required()->maxLength(1024),
                    Select::make('mode')->label('Action')->options(ProtectionPolicy::ACTIONS)->required(),
                    TextInput::make('priority')->numeric()->default(0)->minValue(-1000)->maxValue(1000)->required(),
                    Select::make('classification')->options(array_combine(ProtectionPolicy::CLASSIFICATIONS, array_map(fn ($v) => str_replace('_', ' ', ucfirst($v)), ProtectionPolicy::CLASSIFICATIONS)))->placeholder('All classifications'),
                    Toggle::make('enabled')->default(true),
                    TextInput::make('comment')->maxLength(500)->columnSpanFull(),
                ])->extraItemActions([
                    Action::make('testRule')->label('Test rule')->icon('heroicon-o-beaker')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalContent(function (array $arguments, Repeater $component, $livewire): HtmlString {
                            $policy = new ProtectionPolicy;
                            $rule = $policy->normalize([$component->getRawItemState($arguments['item'])]);
                            $record = $livewire->getRecord();
                            if (! $record?->exists) {
                                return new HtmlString('Create a draft and prepare the baseline to test rules against its inventory.');
                            }
                            $matches = [];
                            foreach ((new FilePolicyCatalog)->rows($record) as $row) {
                                if ($policy->effective($row['path'], $rule, $row['identity'], $row['loader'], $row['classification'])['mode'] !== null) {
                                    $matches[] = '<li class="break-all">'.e($row['path']).' — '.e($row['invariant'] ?? $row['origin']).'</li>';
                                }
                            }

                            return new HtmlString('<p>'.count($matches).' matching files. Existing effective rule is shown; saving may change precedence.</p><ul>'.implode('', array_slice($matches, 0, 250)).'</ul>');
                        }),
                ])->addActionLabel('Add rule'),
        ];
    }
}
