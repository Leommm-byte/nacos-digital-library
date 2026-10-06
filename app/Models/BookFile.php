<?php

namespace App\Models;

use App\Enums\BookSource;
use App\Enums\TextStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $book_id
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $page_count
 * @property TextStatus $text_status
 * @property BookSource $source
 * @property bool $is_current
 */
class BookFile extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'sha256',
        'page_count',
        'text_status',
        'source',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'source' => BookSource::class,
            'text_status' => TextStatus::class,
            'is_current' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'text_status' => 'none',
    ];

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return HasMany<BookScanPage, $this>
     */
    public function scanPages(): HasMany
    {
        return $this->hasMany(BookScanPage::class)->orderBy('page');
    }
}
