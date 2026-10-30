<?php

namespace App\Importers;

use App\Models\Mufti;

/**
 * The V1 mufti cards. Bios are left empty: the owner writes them in the admin.
 */
final class Muftis
{
    /** @var array<string, array{kind: string, name_ar: string, name_en: string}> */
    public const CARDS = [
        'islamweb' => ['kind' => 'institution', 'name_ar' => 'مركز الفتوى بإسلام ويب', 'name_en' => 'Islamweb Fatwa Center'],
        'islamqa' => ['kind' => 'institution', 'name_ar' => 'الإسلام سؤال وجواب', 'name_en' => 'IslamQA'],
        'binbaz' => ['kind' => 'person', 'name_ar' => 'الشيخ عبد العزيز بن باز', 'name_en' => 'Sheikh Abd al-Aziz ibn Baz'],
        'fawzan' => ['kind' => 'person', 'name_ar' => 'الشيخ صالح بن فوزان الفوزان', 'name_en' => 'Sheikh Salih al-Fawzan'],
        'lajnah' => ['kind' => 'institution', 'name_ar' => 'اللجنة الدائمة للبحوث العلمية والإفتاء', 'name_en' => 'The Permanent Committee for Scholarly Research and Ifta'],
    ];

    /**
     * The mufti's id, creating its card on first use. An existing card (possibly edited in the admin) is never changed.
     */
    public static function id(string $key): int
    {
        return Mufti::query()->firstOrCreate(['key' => $key], self::CARDS[$key])->id;
    }

    public static function ensureAll(): void
    {
        foreach (array_keys(self::CARDS) as $key) {
            self::id($key);
        }
    }
}
