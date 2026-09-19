<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\PermissionMatrix;
use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'إدارة الوصول';

    protected static ?string $navigationLabel = 'الأدوار والصلاحيات';

    protected static ?string $modelLabel = 'دور';

    protected static ?string $pluralModelLabel = 'الأدوار';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('معلومات الدور')
                ->schema([
                    Forms\Components\TextInput::make('label_ar')
                        ->label('اسم العرض بالعربية')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state) {
                            if (! $get('id')) {
                                $set('name', Str::slug($state ?: ''));
                            }
                        }),

                    Forms\Components\TextInput::make('name')
                        ->label('المعرّف التقني (Internal Name)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?Role $record) => (bool) $record?->is_system)
                        ->helperText(fn (?Role $record) => $record?->is_system
                            ? 'هذا دور نظامي - لا يمكن تغيير معرّفه التقني.'
                            : 'أحرف إنجليزية صغيرة وشرطات فقط - يُستخدم داخلياً بالكود.'),

                    Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),

                    Forms\Components\Placeholder::make('is_system_badge')
                        ->label('')
                        ->content(fn (?Role $record) => $record?->is_system
                            ? '🛡️ دور نظامي أساسي - لا يمكن حذفه.'
                            : 'دور مخصَّص - يمكن حذفه أو تعديله بحرّية.')
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('صلاحيات الدور')
                ->schema([
                    Forms\Components\Placeholder::make('super_admin_notice')
                        ->label('')
                        ->content('🌟 هذا الدور يملك وصولاً كاملاً غير مقيَّد تلقائياً لكل أجزاء المنصة - لا حاجة لتحديد صلاحيات فردية.')
                        ->visible(fn (Get $get) => $get('name') === 'super-admin'),

                    PermissionMatrix::make('permissions_matrix')
                        ->label('')
                        ->visible(fn (Get $get) => $get('name') !== 'super-admin')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label_ar')->label('الاسم')->description(fn (Role $record) => $record->name),
                Tables\Columns\IconColumn::make('is_system')->label('نظامي')->boolean(),
                Tables\Columns\TextColumn::make('users_count')->label('المستخدمون')->counts('users')->badge(),
                Tables\Columns\TextColumn::make('permissions_count')->label('الصلاحيات')->counts('permissions')->badge()
                    ->formatStateUsing(fn (Role $record) => $record->name === 'super-admin' ? 'الكل' : $record->permissions_count),
                Tables\Columns\TextColumn::make('updated_at')->label('آخر تعديل')->dateTime(),
            ])
            ->defaultSort('sort_order')
            ->searchable(['label_ar', 'name'])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}